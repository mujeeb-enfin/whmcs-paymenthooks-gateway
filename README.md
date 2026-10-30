# PaymentHooks gateway module for WHMCS 8.x

Lets WHMCS clients pay invoices on [PaymentHooks](https://paymenthooks.com/docs/whmcs) hosted
checkout. PaymentHooks performs the payment, routes it to your connected gateway (Razorpay, Stripe and
others) and runs subscriptions and refunds. The module creates checkout sessions, redirects the client,
and reconciles WHMCS invoices from signed webhooks.

- [How it works](#how-it-works) · [Requirements](#requirements) · [Install](#install) · [Upgrade](#upgrade)
- [PaymentHooks setup](#paymenthooks-setup) · [Module settings](#module-settings) · [Custom checkout domain](#custom-checkout-domain)
- [Paying an invoice](#paying-an-invoice) · [Webhook handling](#webhook-handling) · [Recurring billing](#recurring-billing) · [Refunds](#refunds)
- [Logs](#logs) · [Troubleshooting](#troubleshooting) · [Tests](#tests) · [End-to-end checks](#end-to-end-checks)

## How it works

1. The client clicks **Pay Now** on an unpaid invoice.
2. The module creates a PaymentHooks checkout session for the invoice balance, in the client's WHMCS
   currency, and redirects the client to it.
3. PaymentHooks takes the payment through your connected gateway.
4. PaymentHooks sends `checkout.session.completed` to the module's callback. The module verifies the
   signature and records the payment on the invoice, with the PaymentHooks payment id as the transaction id.

Cards are entered only on the PaymentHooks page, never in WHMCS. The module stores no card tokens.

## Requirements

- WHMCS 8.x (gateway module API 1.1).
- PHP 7.4 to 8.x with the cURL and JSON extensions, and outbound HTTPS to `paymenthooks.com`.
- A PaymentHooks account with a gateway connected under **Gateways** that supports your WHMCS currencies.
- Your WHMCS site reachable over HTTPS from the internet, so PaymentHooks can deliver webhooks.

## Install

1. Copy the contents of `modules/gateways/` into your WHMCS `modules/gateways/` directory, keeping
   the structure:
   ```
   modules/gateways/paymenthooks.php                      gateway module (settings, Pay button, refund, cancel)
   modules/gateways/callback/paymenthooks.php             Pay Now handler and webhook receiver
   modules/gateways/paymenthooks/lib/bootstrap.php        loads the library
   modules/gateways/paymenthooks/lib/Config.php           settings and validation
   modules/gateways/paymenthooks/lib/Client.php           PaymentHooks API client
   modules/gateways/paymenthooks/lib/CheckoutBuilder.php  checkout session request
   modules/gateways/paymenthooks/lib/InvoiceMapper.php    invoice balance, line items, amounts
   modules/gateways/paymenthooks/lib/RefundMapper.php     refund amount in USD cents
   modules/gateways/paymenthooks/lib/PriceResolver.php    recurring product and price mapping
   modules/gateways/paymenthooks/lib/EventParser.php      webhook event fields
   modules/gateways/paymenthooks/lib/WebhookRouter.php    applies events to WHMCS
   modules/gateways/paymenthooks/lib/SubscriptionSync.php renewals and cancellations
   modules/gateways/paymenthooks/lib/Signature.php        webhook signature check
   modules/gateways/paymenthooks/lib/GatewayLog.php       Gateway Log entries
   modules/gateways/paymenthooks/lib/Logger.php           Module and Activity Log
   modules/gateways/paymenthooks/lib/Schema.php           the module's tables
   modules/gateways/paymenthooks/templates/pay_button.tpl
   modules/gateways/paymenthooks/whmcs.json
   modules/gateways/paymenthooks/logo.png
   ```
   There is no hooks file and nothing to install outside `modules/gateways/`.
2. In WHMCS admin open **Configuration > System Settings > Payment Gateways**, find **PaymentHooks**
   under "All Payment Gateways" and activate it.
3. Fill in the [settings](#module-settings). The module creates its tables on first use; there is no
   SQL to run.

## Upgrade

Replace **all** files listed above; files from earlier versions do not work with the new ones. Settings
are kept. Tables are upgraded automatically on the next request:

| Table | Holds | Added on upgrade |
|-------|-------|------------------|
| `mod_paymenthooks_events` | Webhook event ids already processed | `status` (existing rows become `done`) |
| `mod_paymenthooks_sessions` | Invoice to checkout session, payment and subscription | `fee_minor` |
| `mod_paymenthooks_price_map` | WHMCS product, cycle, currency and amount to PaymentHooks price | `currency`, `amount_minor` |
| `mod_paymenthooks_install` | A random id for this install, used in idempotency keys | new table |

Versions before this one asked for `sk_`/`pk_` test and live keys. Enter your `phk_live_` secret key and
the endpoint's `whsec_` secret again.

### Money field rename (PaymentHooks API, 2026-10-29)

The PaymentHooks REST API renamed its money fields (SDK 3.0.0) and accepts only the new names. Earlier
module files send the retired names and fail with `VALIDATION_ERROR`; replace them. What changed in the
module:

| Call | Before | Now |
| --- | --- | --- |
| `POST /checkout-sessions` | `currency`, `taxAmount`, `convenienceFee` | `itemLocalCurrency`, `itemTaxLocalCurrencyAmount` (always 0), `itemConvenienceFeeLocalCurrencyAmount` (line items keep `unitPrice`, minor units) |
| `POST /prices` | `amount`, `currency` | `itemLocalCurrencyAmount`, `itemLocalCurrency` |
| `GET /checkout-sessions/:id` (session reuse) | `subtotal` | `itemSubtotalLocalCurrencyAmount` |
| `POST /payments/:id/refund` | `amount` in the invoice currency's minor units | `transactionAmountUsdCents` (**USD cents**), mapped from the payment's `transactionLocalCurrencyAmount` / `transactionAmountUsdCents` (one extra `GET /payments/:id`) |

Webhooks are unchanged: they keep Stripe's shapes (`amount_total`, `amount_refunded`, `currency`, ...),
which is what the module records. They also carry `amount_usd_cents` and `usd_to_local_currency_rate`;
the module reads `amount_usd_cents` for its logs only.

## PaymentHooks setup

1. **API key.** Under **Developers > API Keys**, create a secret key (`phk_live_...`) with the scopes
   `payments:read`, `payments:write`, `customers:read` and `customers:write`. For subscriptions also add
   `subscriptions:write`, `products:read` and `products:write`.
2. **Webhook endpoint.** Under **Developers > Webhooks**, add the **Webhook URL** shown in the module
   settings (`https://<your WHMCS>/modules/gateways/callback/paymenthooks.php`) and subscribe it to:
   - `checkout.session.completed`
   - `checkout.session.payment_failed`
   - `payment.refunded`
   - `subscription.renewed`
   - `subscription.renewal_failed`
   - `subscription.cancelled`

   Other events are accepted and logged as `Ignored`. Copy the endpoint's signing secret (`whsec_...`)
   into the module.
3. **Test, then live.** PaymentHooks has one key type. Payments are simulated or real according to your
   business's **Platform Mode** in the PaymentHooks dashboard. Test with Platform Mode on Test, then switch
   it to Live; nothing changes in WHMCS.

## Module settings

| Field | Notes |
|-------|-------|
| Secret Key | `phk_live_...`. Required. |
| Publishable Key | `phk_pub_...`. Optional, not used by the redirect checkout. |
| Webhook Signing Secret | `whsec_...` of the webhook endpoint. Required. |
| Webhook URL | Read-only. Register this as the webhook endpoint. |
| Custom Checkout Domain | Optional, e.g. `payments.example.com`. See [below](#custom-checkout-domain). |
| Convenience Fee % | Optional surcharge between 0 and 100, charged as balance x %. The invoice is credited with exactly its balance; the surcharge is noted in the Gateway Log entry (it is not booked as a gateway fee, which WHMCS treats as your cost). |
| Description Template | Stored on the checkout session as `metadata.description`. `{invoiceid}` is replaced. Default `Invoice {invoiceid}`. |
| Checkout Heading | Shown above the Pay button. Default "Pay securely with PaymentHooks". |
| Checkout Description | Shown under the heading. Leave blank to hide. |
| Logo URL | Image above the Pay button. If blank, `modules/gateways/paymenthooks/logo.png` is used when present. The logo on the PaymentHooks page comes from your PaymentHooks branding. |
| Enable Subscriptions | Lets recurring products start a PaymentHooks subscription. |
| Payment Mode | `Auto` (default): subscription for recurring products, one-time otherwise. `Subscription only`: every invoice must contain a recurring product; needs Enable Subscriptions, otherwise checkout fails. `One-time only`: never start a subscription. |
| Debug Logging | Logs every API call to **Utilities > Logs > Module Log**. Errors are always written to the **Activity Log**. |

If the Secret Key or Webhook Signing Secret is missing or has the wrong prefix, or the fee is out of
range, the invoice shows "Online payment is unavailable. Please contact us." and the reason is in the
Activity Log.

## Custom checkout domain

If you verified a custom domain in PaymentHooks (**Settings > Custom Domain**, status Active), put it in
**Custom Checkout Domain**, for example `payments.example.com`. Then:

- Clients are redirected to `https://payments.example.com/checkout/...`; the page, the payment and the
  return to WHMCS all run on your domain.
- API calls from WHMCS still go to `https://paymenthooks.com/api/v1`. A custom domain serves only
  customer-facing pages and answers 404 to merchant API routes, so never use it as the API address.
- A checkout from another PaymentHooks account cannot be opened on your domain.
- Leave the field blank to use `paymenthooks.com`.

## Paying an invoice

When the client clicks **Pay Now**:

| Situation | Result |
|-----------|--------|
| Not logged in, or not the invoice owner | Back to the invoice with `paymentfailed`. |
| Invoice already Paid | Back to the invoice with `paymentsuccess`. |
| Invoice Cancelled, Refunded, Collections or Draft | Back to the invoice with `paymentfailed`. |
| A payment for this invoice is complete or in progress at PaymentHooks but its webhook has not arrived | Back to the invoice with `paymentsuccess`; no second checkout is created. |
| An open session exists for the same balance and mode, expiring in more than a minute | The client is sent back to it. |
| Otherwise | A new session is created; older ones are marked stale. |

The session is for the invoice **balance** (total less payments and credit, as WHMCS reports it), in the
client's WHMCS currency, with WHMCS tax already included. The module tells PaymentHooks not to add tax.
If the invoice rows add up to the balance they are shown as line items; otherwise one line "Invoice #N"
for the balance is sent.

Checkout stops with an error, instead of charging a different amount, when:

- the client has no email address or WHMCS currency;
- the balance is zero;
- a subscription cannot be priced (no recurring service, unsupported cycle, zero amount, or PaymentHooks
  refused the product or price);
- PaymentHooks rejects the request or cannot be reached.

The client then returns to the invoice with `paymentfailed`. The Gateway Log shows `Checkout Error` with
the reason, and the Activity Log has `PaymentHooks error [checkout-click]`.

## Webhook handling

The callback checks the `X-PaymentHooks-Signature` header (HMAC-SHA256, 5-minute window, secret
rotation supported). Each event id is processed once.

| Event | Action | Gateway Log |
|-------|--------|-------------|
| `checkout.session.completed` | Records the payment on the invoice, minus any convenience fee | `Successful` |
| (same payment already recorded) | Nothing | `Duplicate` |
| (paid currency differs from the invoice currency) | Not applied; record it manually | `Needs Attention` |
| (invoice not found, or event missing payment details) | Not applied | `Needs Attention` |
| (checkout not created by this WHMCS) | Nothing | `Ignored` |
| `checkout.session.payment_failed` | Nothing; the invoice stays unpaid | `Failure` |
| `payment.refunded` | Noted, for a payment recorded here | `Refund Notification` |
| `subscription.renewed` | Records the charge on the renewal invoice | `Subscription Renewal Paid` |
| (no renewal invoice yet) | Asks PaymentHooks to redeliver | `Retry Requested` |
| (charge differs from the invoice balance) | Recorded, flagged for review | `Needs Attention` |
| (renewal covered by credit) | Nothing to record | `Ignored` |
| `subscription.renewal_failed` | Nothing; PaymentHooks retries on its dunning schedule | `Failure` |
| `subscription.cancelled` | Unbinds the subscription from the service | `Subscription Cancelled` |
| Any other event type | Nothing | `Ignored` |

The callback answers `200` when an event is handled (including duplicates and events it cannot apply, so
they are not redelivered), `401` for a bad signature, `400` for a body that is not an event, and `500` to
ask for redelivery (no renewal invoice yet, or an error). PaymentHooks redelivers after 1 minute,
5 minutes, 30 minutes and 2 hours.

## Recurring billing

PaymentHooks has no off-session charge API, so **PaymentHooks owns the billing schedule**.

1. **First payment.** The module maps the first recurring service on the invoice (product, billing cycle,
   currency, amount) to a PaymentHooks product and recurring price, created on first use and cached in
   `mod_paymenthooks_price_map`, and opens the checkout in subscription mode with that price.
2. **Binding.** On `checkout.session.completed` the subscription id is stored on that service
   (`tblhosting.subscriptionid`).
3. **Renewals.** PaymentHooks charges each cycle and sends `subscription.renewed`. The module records the
   charged amount on the oldest unpaid invoice for that service in the same currency. If the amount differs
   from the invoice balance (tax, credit, a combined invoice) it is still recorded, and the Gateway Log and
   Activity Log ask you to review the invoice.
4. **Cancellation.** Cancelling or terminating the WHMCS service cancels the subscription at PaymentHooks
   immediately (an already-cancelled subscription counts as success). A subscription cancelled in
   PaymentHooks is unbound from the service.

Required WHMCS settings:

- Turn off WHMCS's own capture for these products: WHMCS raises the renewal invoice, and the payment
  arrives from the PaymentHooks webhook.
- Set **Automation Settings > Invoice Generation** so renewal invoices exist before PaymentHooks charges;
  otherwise renewals end as `Retry Requested` and must be recorded by hand.
- Keep recurring products on their own invoice with no setup fee. The first charge is the recurring price;
  a setup fee or promo on the same invoice leaves it under- or over-paid.

| WHMCS cycle | PaymentHooks interval | intervalCount |
|-------------|-----------------------|---------------|
| Weekly | week | 1 |
| Monthly | month | 1 |
| Quarterly | month | 3 |
| Semi-Annually | month | 6 |
| Annually | year | 1 |
| Biennially | year | 2 |
| Triennially | year | 3 |

One Time and Free products are never subscriptions.

## Refunds

**Refund** on a PaymentHooks transaction in WHMCS reads the payment (`GET /payments/:id`) and calls
`POST /payments/:id/refund` with `transactionAmountUsdCents`: the amount, full or partial, converted to USD cents pro rata from what the
payment charged (`transactionLocalCurrencyAmount` against `transactionAmountUsdCents`). PaymentHooks then
refunds the gateway in the currency it charged. The refund is declined, with the reason in the Gateway Log,
when the WHMCS currency differs from the charged currency, when it exceeds what is left to refund, or when it
is under one US cent. A refund made in the PaymentHooks dashboard arrives as
`payment.refunded` and is noted in the Gateway Log for you to reflect in WHMCS.

## Logs

| Where | What |
|-------|------|
| **Billing > Gateway Log** | One entry for every webhook delivery (statuses above, plus `Invalid Signature`, `Invalid Payload` and `Error`) with the event id, type, HTTP status returned and a plain result. Checkout errors appear as `Checkout Error`. |
| **Utilities > Logs > Activity Log** | Every error, and anything you need to fix by hand. Always on. |
| **Utilities > Logs > Module Log** | Raw API requests and responses, only with Debug Logging ticked and WHMCS module debugging on. |

Secrets are masked in every log.

## Troubleshooting

| Symptom | Check |
|---------|-------|
| Gateway Log stays empty after a payment | PaymentHooks is not reaching WHMCS. Check the endpoint URL and its delivery log under **Developers > Webhooks** in PaymentHooks. |
| `Invalid Signature` | The Webhook Signing Secret must be the `whsec_` secret of this endpoint, and the server clock must be within 5 minutes. |
| "Online payment is unavailable" on the invoice | A setting is missing or invalid; see the Activity Log. |
| `Checkout Error` | The reason is in the entry; `INSUFFICIENT_SCOPE` means the key lacks a scope. |
| Renewal `Retry Requested` | No unpaid renewal invoice exists yet; generate invoices earlier. |
| `Needs Attention` | Read the entry; it says what to record by hand. |

## Tests

Without WHMCS or a network (PHP 7.4+):

```
php tests/run.php              # signature, settings, amounts, line items, the checkout and price requests, refund USD cents, event parsing, billing cycles
php tests/webhook-harness.php  # the full webhook path: what lands on invoices and in the Gateway Log for every event
```

Both exit 0 when all checks pass and 1 otherwise. `tests/fixtures/*.json` are real envelopes produced by
PaymentHooks' webhook builders (regenerated by `src/services/webhooks/whmcs-fixtures.test.ts` in the
PaymentHooks repository). The harness uses an in-memory stand-in for the WHMCS tables and functions; a run
on a real WHMCS install is the final check.

## End-to-end checks

With Platform Mode on Test:

| Scenario | Expected |
|----------|----------|
| Pay an invoice | Invoice marked Paid; transaction id is the PaymentHooks payment id; Gateway Log `Successful` |
| Cancel on the checkout page | Invoice stays Unpaid |
| Resend the event from **Developers > Webhooks** | Gateway Log `Duplicate`; applied once |
| Wrong signing secret | Callback returns 401; Gateway Log `Invalid Signature` |
| Refund in WHMCS, full and partial | Refund recorded in WHMCS and shown in PaymentHooks |
| Recurring product | Service shows the subscription id; cancelling the service cancels it in PaymentHooks |

## License

MIT, see [LICENSE](LICENSE).
