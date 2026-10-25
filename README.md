# PaymentHooks gateway module for WHMCS 8.x

Connects WHMCS to [PaymentHooks](https://paymenthooks.com/docs). PaymentHooks
performs the payment, hosted checkout, gateway routing (Razorpay, Stripe and
others), subscriptions and refunds. This module creates checkout sessions,
redirects the client to the hosted page, and reconciles WHMCS invoices from
signed PaymentHooks webhooks.

## Features

- One-time invoice payments through the hosted checkout.
- Full and partial refunds from the WHMCS admin.
- Recurring billing: the first payment starts a PaymentHooks subscription,
  PaymentHooks charges each cycle, and each charge is applied to the matching
  WHMCS renewal invoice.
- Signed webhooks (HMAC-SHA256, 5-minute replay window, secret rotation),
  each event applied once (event-id claim plus a transaction-id check).
- Fails hard and logs: a misconfigured key, unknown currency, or unpriceable
  subscription shows an error instead of charging the wrong amount.
- Optional custom checkout domain: clients pay on your own domain.

## Requirements

- WHMCS 8.x, PHP 7.4 or later with cURL.
- A PaymentHooks account with a gateway connected under **Gateways**.

## Install

1. Copy the contents of `modules/gateways/` into your WHMCS `modules/gateways/`
   directory, keeping the structure:
   ```
   modules/gateways/paymenthooks.php
   modules/gateways/callback/paymenthooks.php
   modules/gateways/paymenthooks/lib/*.php
   modules/gateways/paymenthooks/templates/pay_button.tpl
   modules/gateways/paymenthooks/whmcs.json
   modules/gateways/paymenthooks/logo.png
   ```
2. In WHMCS admin, open **Configuration > System Settings > Payment Gateways**,
   find **PaymentHooks** under "All Payment Gateways" and activate it.
3. Fill in the settings below. The module's tables are created on first use.

## PaymentHooks setup

1. **API key.** In PaymentHooks open **Developers > API Keys** and create a
   secret key (`phk_live_...`) with the scopes `payments:read`,
   `payments:write`, `customers:read` and `customers:write`. For subscriptions
   also add `subscriptions:write`, `products:read` and `products:write`.
2. **Webhook endpoint.** Open **Developers > Webhooks**, add the **Webhook URL**
   shown in the module settings, and subscribe it to:
   - `checkout.session.completed`
   - `checkout.session.payment_failed`
   - `payment.refunded`
   - `subscription.renewed`
   - `subscription.renewal_failed`
   - `subscription.cancelled`

   Copy the endpoint's signing secret (`whsec_...`) into the module.
3. **Test or live.** PaymentHooks has one key type. Whether payments are
   simulated or real is your business's **Platform Mode** in the PaymentHooks
   dashboard. Test with Platform Mode set to Test, then switch it to Live.

## Module settings

| Field | Notes |
|-------|-------|
| Secret Key | `phk_live_...`. Required. |
| Publishable Key | `phk_pub_...`. Optional, not used by the redirect checkout. |
| Webhook Signing Secret | `whsec_...` from the webhook endpoint. Required. |
| Webhook URL | Read-only. Register this as the webhook endpoint. |
| Custom Checkout Domain | Optional, e.g. `pay.example.com`, if you verified a custom domain in PaymentHooks. Clients are sent to the checkout page on that domain. API calls always go to `paymenthooks.com`. |
| Convenience Fee % | Optional surcharge added to the checkout. The invoice is credited with exactly its balance; the surcharge is noted in the Gateway Log entry (it is not booked as a gateway fee, which WHMCS treats as your cost). |
| Description Template | Stored on the checkout session (`metadata.description`). `{invoiceid}` is replaced. |
| Enable Subscriptions | Lets recurring products start a PaymentHooks subscription. |
| Payment Mode | `Auto` (subscription for recurring products, one-time otherwise), `Subscription only` (an invoice without a recurring product fails at checkout), or `One-time only`. |
| Debug Logging | Logs every API call to **Utilities > Logs > Module Log**. Errors are always written to the **Activity Log**. |

## Logs

| Where | What |
|-------|------|
| **Billing > Gateway Log** | One entry for every webhook delivery, whatever happened: `Successful`, `Subscription Renewal Paid`, `Refund Notification`, `Subscription Cancelled`, `Failure`, `Duplicate`, `Ignored`, `Needs Attention`, `Retry Requested`, `Invalid Signature`, `Invalid Payload`, `Error`. Each entry has the event id and type, the HTTP status returned to PaymentHooks and a plain-language result. Checkout errors are logged as `Checkout Error`. |
| **Utilities > Logs > Activity Log** | Every error, and anything you need to fix by hand. Always on. |
| **Utilities > Logs > Module Log** | Raw API requests and responses, only with Debug Logging ticked (and WHMCS module debugging on). |

If the Gateway Log stays empty after a payment, PaymentHooks is not reaching the callback: check the endpoint
URL under **Developers > Webhooks** in PaymentHooks and its delivery log there.

## Custom checkout domain

If you verified a custom domain in PaymentHooks (**Settings > Custom Domain**, status Active), put it in
**Custom Checkout Domain**, for example `payments.example.com`. Then:

- Clients are redirected to `https://payments.example.com/checkout/...`; the page, the payment and the return to
  WHMCS all run on your domain.
- API calls from WHMCS still go to `https://paymenthooks.com/api/v1`. A custom domain serves only customer-facing
  pages, and answers 404 to every merchant API route, so it must never be used as the API address.
- A checkout from another PaymentHooks account cannot be opened on your domain.
- Leave the field blank to use `paymenthooks.com`.

## How payments are recorded

- The client clicks **Pay Now**. The module creates a checkout session for the
  invoice balance, in the client's currency, and redirects to it. Refreshing
  reuses the open session for the same balance.
- If the invoice rows add up to the balance they are shown as line items;
  otherwise (tax, credit, partial payments) one line for the balance is sent.
- On `checkout.session.completed` the payment (`payment_intent`) is applied to
  the invoice with `amount_total` minus any convenience fee. A payment in a
  different currency from the invoice is logged and not applied.
- The client's WHMCS currency must exist in PaymentHooks' supported list and be
  supported by the connected gateway. There is no default currency.

## Recurring billing

PaymentHooks has no off-session charge API, so **PaymentHooks owns the billing
schedule**.

1. **First payment.** The module maps the WHMCS product, billing cycle,
   currency and amount to a PaymentHooks product and recurring price (created
   on first use, cached in `mod_paymenthooks_price_map`). The checkout opens in
   subscription mode with that price. If no price can be made (unsupported
   cycle, API error) checkout fails and the reason is in the Activity Log.
2. **Binding.** On `checkout.session.completed` the subscription id is stored
   on the WHMCS service (`tblhosting.subscriptionid`).
3. **Renewals.** PaymentHooks charges each cycle and sends
   `subscription.renewed` with the charge (`latest_payment`). The module records
   the amount actually charged on the oldest unpaid invoice for that service
   (same currency). If that differs from the invoice balance (tax, credit, a
   combined invoice) it is still recorded and an Activity Log entry asks you to
   review the invoice. If WHMCS has not raised the invoice yet, the module asks
   for redelivery (PaymentHooks retries after 1 min, 5 min, 30 min and 2 h) and
   logs each miss, so generate renewal invoices ahead of the due date.
4. **Cancellation.** Cancelling or terminating the WHMCS service cancels the
   subscription at PaymentHooks immediately. A subscription cancelled in
   PaymentHooks is unbound from the service.

### Required WHMCS settings

- Turn off WHMCS's own capture for these products: WHMCS raises the renewal
  invoice, and the payment arrives from the PaymentHooks webhook.
- Set **Automation Settings > Invoice Generation** so renewal invoices exist
  before PaymentHooks charges.
- Keep recurring products on their own invoice with no setup fee. The first
  charge is the recurring price; a setup fee or promo on the same invoice
  leaves it under- or over-paid.

### Billing-cycle mapping

| WHMCS cycle | PaymentHooks interval | intervalCount |
|-------------|-----------------------|---------------|
| Weekly | week | 1 |
| Monthly | month | 1 |
| Quarterly | month | 3 |
| Semi-Annually | month | 6 |
| Annually | year | 1 |
| Biennially | year | 2 |
| Triennially | year | 3 |

## Refunds

**Refund** in WHMCS calls `POST /payments/:id/refund` with the amount, in the
payment's currency. A refund made in the PaymentHooks dashboard arrives as
`payment.refunded` and is logged in the Gateway Log for you to reflect in
WHMCS.

## Limits

- Hosted redirect only; cards are never entered in WHMCS.
- Renewals cannot be triggered from the WHMCS cron.

## Tests

Without WHMCS or a network:

```
php tests/run.php              # signature, config, amounts, checkout request, event parsing
php tests/webhook-harness.php  # the full webhook path, including what lands in the Gateway Log
```

The harness uses an in-memory stand-in for the WHMCS tables and functions the module calls; a real WHMCS run
is still the final check.

`tests/fixtures/*.json` are real envelopes produced by PaymentHooks' webhook
builders (`src/services/webhooks/whmcs-fixtures.test.ts` in the PaymentHooks
repository regenerates them).

## End-to-end checks

With Platform Mode set to Test in PaymentHooks:

| Scenario | Expected |
|----------|----------|
| Pay an invoice | Invoice marked Paid; transaction id is the PaymentHooks payment id |
| Cancel on the checkout page | Invoice stays Unpaid |
| Resend the event from Developers > Webhooks | Applied once |
| Wrong signing secret | Callback returns 401; error in the Module Log |
| Refund in WHMCS, full and partial | Refund recorded; PaymentHooks shows the refund |
| Recurring product | Service shows the subscription id; cancelling the service cancels it in PaymentHooks |

## License

MIT, see [LICENSE](LICENSE).
