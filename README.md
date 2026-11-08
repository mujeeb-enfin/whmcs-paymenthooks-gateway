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

1. The client clicks **Pay Now** (pay once) or, when you enabled subscriptions, **Subscribe & Auto-pay**
   (pay now and let PaymentHooks renew the hosting, domain and addons automatically).
2. The module creates a PaymentHooks checkout session for the invoice balance, in the client's WHMCS
   currency, and redirects the client to it.
3. PaymentHooks takes the payment through your connected gateway.
4. PaymentHooks sends `checkout.session.completed` to the module's callback. The module verifies the
   signature and records the payment on the invoice, with the PaymentHooks payment id as the transaction id.

Cards are entered only on the PaymentHooks page, never in WHMCS. The module stores no card tokens and has
no capture function: **WHMCS never triggers a charge.** PaymentHooks takes every payment, including each
renewal, and tells WHMCS by webhook.

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
   modules/gateways/paymenthooks/lib/PriceResolver.php    billing cycle to interval
   modules/gateways/paymenthooks/lib/RecurringPlanner.php what Subscribe & Auto-pay subscribes and charges once
   modules/gateways/paymenthooks/lib/SubscriptionMatcher.php subscriptions of one checkout to their items
   modules/gateways/paymenthooks/lib/InvoicePlan.php      reads an invoice's hosting, domains and addons
   modules/gateways/paymenthooks/lib/PayButton.php        Pay Now and Subscribe buttons
   modules/gateways/paymenthooks/lib/SubscriptionMap.php  subscription to services, domains and addons
   modules/gateways/paymenthooks/lib/RenewalInvoice.php   finds or raises the renewal invoice
   modules/gateways/paymenthooks/lib/RefundSync.php       records PaymentHooks refunds in WHMCS
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
| `mod_paymenthooks_sessions` | Invoice to checkout session, payment and subscription | `fee_minor`, `plan_json` |
| `mod_paymenthooks_subscriptions` | Subscription to each service, domain and addon it renews | new table |
| `mod_paymenthooks_refunds` | Refunds known per payment, so none is booked twice | new table |
| `mod_paymenthooks_price_map` | Earlier versions' product/price cache; no longer written | `currency`, `amount_minor` |
| `mod_paymenthooks_install` | A random id for this install, used in idempotency keys | new table |

### Phone numbers checked worldwide (3.1.1 to 3.1.2)

Replace all files; there is no table change. PaymentHooks (from 2026-11-08) validates a customer's phone
against the numbering plan of its country and stores it in E.164. WHMCS formats such as `+91.9746102494`
are accepted. When PaymentHooks refuses only the client's phone (not a valid number for its country), 3.1.2
creates the customer without it, writes `customer phone not accepted` to the Module Log, and checkout asks
the client for the number. 3.1.1 stopped such a checkout with `paymentfailed`.

### Discounted first periods and mixed cycles (3.1.0 to 3.1.1)

Replace all files; there is no table change (`plan_json` now also stores the billing groups). What changes
for clients and admins:

- **Discounted first period** (first-year domain price, promotion month): Subscribe & Auto-pay is now
  offered. This period is charged once at its actual price and the item renews at its full price from its
  next due date (`recurring.startsAt`). 3.1.0 showed only Pay Now on such invoices.
- **Mixed cycles** (monthly hosting + yearly domain): every recurring item is subscribed in one checkout,
  one subscription per billing group, one payment now. 3.1.0 charged the other cycle once and left it to
  its renewal invoice.
- **A note under Pay Now** (`paymenthooks_pay_subscribe_unavailable`) says why Subscribe is not offered;
  the reason is also in the Activity Log (`PaymentHooks info [subscribe-unavailable]`).
- **Requires** a PaymentHooks API that keeps `lineItems[].recurring.startsAt` and returns `subscriptions`
  for a checkout with several billing groups. Without it Subscribe is refused with
  `RECURRING_LINE_ITEMS_UNSUPPORTED` (logged, the client is not charged) and Pay Now still works.
- Subscriptions bound by 3.1.0 keep renewing unchanged.

### Subscribe & Auto-pay (3.1.0)

The **Payment Mode** setting is gone: the client now chooses between **Pay Now** and **Subscribe &
Auto-pay**, and **Enable Subscriptions** only shows the Subscribe button. On the first request after the
upgrade the old value is migrated and removed (noted in the Activity Log): `One-time only` turns Enable
Subscriptions off; `Auto` and `Subscription only` keep your Enable Subscriptions choice. Services already
subscribed by earlier versions keep renewing. Subscribe the webhook endpoint to `subscription.deleted` and
`invoice.paid` as well (see [setup](#paymenthooks-setup)).

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
   `subscriptions:write`.
2. **Webhook endpoint.** Under **Developers > Webhooks**, add the **Webhook URL** shown in the module
   settings (`https://<your WHMCS>/modules/gateways/callback/paymenthooks.php`) and subscribe it to:
   - `checkout.session.completed`
   - `checkout.session.payment_failed`
   - `payment.refunded`
   - `subscription.renewed`
   - `subscription.renewal_failed`
   - `subscription.cancelled`
   - `subscription.deleted`
   - `invoice.paid`

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
| Enable Subscriptions | Shows **Subscribe & Auto-pay** next to Pay Now on invoices with hosting, domains or addons that can renew. The client chooses. |
| Subscribe Button Text | Label of that button. Default "Subscribe & Auto-pay". |
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

When the client clicks **Pay Now** or **Subscribe & Auto-pay**:

| Situation | Result |
|-----------|--------|
| Not logged in, or not the invoice owner | Back to the invoice with `paymentfailed`. |
| Subscribe pressed but Enable Subscriptions is off, or the invoice cannot be subscribed (see [below](#recurring-billing)) | Back to the invoice with `paymentfailed`; nothing is charged. |
| Invoice already Paid | Back to the invoice with `paymentsuccess`. |
| Invoice Cancelled, Refunded, Collections or Draft | Back to the invoice with `paymentfailed`. |
| A payment for this invoice is complete or in progress at PaymentHooks but its webhook has not arrived | Back to the invoice with `paymentsuccess`; no second checkout is created. |
| An open session exists for the same balance and mode, expiring in more than a minute | The client is sent back to it. |
| Otherwise | A new session is created; older ones are marked stale. |

The session is for the invoice **balance** (total less payments and credit, as WHMCS reports it), in the
client's WHMCS currency, with WHMCS tax already included. The module tells PaymentHooks not to add tax.
Pay Now sends the invoice's own rows as line items, built like the Subscribe & Auto-pay lines:
promotion lines are folded into the item they discount, exclusive WHMCS tax is spread over the taxed
rows (each line is its price with tax; the tax amount is still sent so the checkout and receipt show
it), and on a part-paid or credit-applied invoice each row is scaled to its share of the balance
(remainder on the last row; the amount already paid or credited is sent as session metadata). Only
when the rows cannot add up to the balance exactly (for example a negative line not tied to an item,
since PaymentHooks line items cannot be negative) is one line "Invoice #N" for the balance sent, and
the reason is written to the Activity Log as `PaymentHooks info [checkout-single-line]`.

The client's name, email, phone and country are sent with the PaymentHooks customer, so checkout does not
ask for them again. PaymentHooks validates the phone against the numbering plan of its country (worldwide;
WHMCS formats such as `+91.9746102494` are accepted) and stores it in E.164; checkout shows it as a country
selector and the number. If PaymentHooks refuses only the phone (not a valid number for its country), the
customer is created without it, the Module Log has `customer phone not accepted`, and checkout asks the
client for the number.

Checkout stops with an error, instead of charging a different amount, when:

- the client has no email address or WHMCS currency;
- the balance is zero;
- Subscribe & Auto-pay was pressed for an invoice that cannot be subscribed;
- PaymentHooks did not keep the `recurring` line items (`RECURRING_LINE_ITEMS_UNSUPPORTED`: the session
  would charge once and never renew, so the client is not sent to it);
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
| `payment.refunded` | A refund made in PaymentHooks is recorded on the invoice (only the part WHMCS does not know yet) | `Refund Notification` |
| (refund in another currency, or more than WHMCS credited) | What can be recorded is; the rest is flagged | `Needs Attention` |
| `subscription.renewed`, `invoice.paid` | Records the charge on the renewal invoice(s) of the subscribed items | `Subscription Renewal Paid` |
| (both events for one renewal) | The second is a duplicate | `Duplicate` |
| (no renewal invoice yet, item due) | WHMCS raises it (GenInvoices), then the charge is recorded | `Subscription Renewal Paid` |
| (item due, still no invoice) | Asks PaymentHooks to redeliver | `Retry Requested` |
| (WHMCS shows the period paid, nothing due) | Not applied; add credit or refund | `Needs Attention` |
| (charge differs from the invoice balance) | Recorded, flagged for review | `Needs Attention` |
| (renewal covered by credit) | Nothing to record | `Ignored` |
| `subscription.renewal_failed` | Note on the renewal invoice; WHMCS overdue handling applies; PaymentHooks retries | `Failure` |
| `subscription.cancelled`, `subscription.deleted` | The items stop auto-renewing (unbound) | `Subscription Cancelled` |
| Any other event type | Nothing | `Ignored` |

The callback answers `200` when an event is handled (including duplicates and events it cannot apply, so
they are not redelivered), `401` for a bad signature, `400` for a body that is not an event, and `500` to
ask for redelivery (no renewal invoice yet, or an error). PaymentHooks redelivers after 1 minute,
5 minutes, 30 minutes and 2 hours.

## Recurring billing

**PaymentHooks does all the billing; WHMCS never charges.** The client subscribes once; from then on
PaymentHooks charges every renewal itself through your connected gateway (card mandate, UPI Autopay,
e-mandate and the like, where the gateway supports it), sends the merchant webhooks, and the module records
each charge on the WHMCS renewal invoice. The module has no capture function and stores no tokens.

### Subscribe & Auto-pay

With **Enable Subscriptions** ticked, invoices with hosting, domains or addons show **Subscribe & Auto-pay**
next to **Pay Now**. Under the buttons the client sees what is paid now and, for each subscription, what renews,
at what price, how often and from when, for example:

> With Subscribe & Auto-pay you pay **₹1,350.25** now, then until you cancel:
> ₹1,532.82 every year from 7 Nov 2027: Domain Registration - taptipm.com (₹1,532.82)

Pay Now always pays the invoice once.

When Subscribe is not offered, a short note under Pay Now (`paymenthooks_pay_subscribe_unavailable`) says
why, and the reason is written once per invoice view to the Activity Log as
`PaymentHooks info [subscribe-unavailable]`. If the invoice could not be read, the note says "Auto-pay is
unavailable for this invoice right now" and the error is logged as `PaymentHooks error [subscribe-plan]`.
With Enable Subscriptions off there is no button and no note.

| Reason code (log) | Note shown |
|---|---|
| `part_paid` | Part of this invoice has already been paid ... auto-pay can start from the next renewal invoice. |
| `credit_applied` | Account credit was applied to this invoice ... |
| `lines_mismatch` | The invoice lines do not add up to the amount due ... |
| `negative_line` | A discount or credit line that is not tied to an item. |
| `free_renewal` | The items renew free of charge. |
| `already_subscribed` | The items already renew automatically. |
| `nothing_recurring` | Nothing on the invoice renews. |
| `cycle_unsupported` | The billing cycle cannot renew automatically. |
| `period_ended`, `due_date_changed`, `no_due_date` | A discounted item whose period dates cannot be worked out reliably; auto-pay can start from its next renewal invoice. |
| `mixed` | Different items are ineligible for different reasons. |

What each subscription covers (a subscription has one billing cycle and one start date):

| Invoice line | Renews at | How often |
|---|---|---|
| Hosting (`tblhosting`) | the service's recurring amount | its billing cycle (table below) |
| Domain registration, transfer or renewal (`tbldomains`) | the domain's recurring amount | every *registration period* years |
| Addon (`tblhostingaddons`) | the addon's recurring amount | its billing cycle |
| Setup fee, one-off item, late fee | charged once | - |

- **Every recurring item, one checkout.** All eligible hosting, domains and addons on the invoice are
  subscribed together. PaymentHooks groups the recurring lines by billing cycle and start date and opens one
  subscription per group, with one payment now and one mandate: monthly hosting and a yearly domain become a
  monthly and a yearly subscription. 12 months and 1 year are one cycle.
- **Full price** (this period costs the renewal price): the line is sent at its renewal price without a start
  date, so the subscription's first charge, today, pays this period and the first renewal is one cycle later.
  A first period dearer than renewal (setup fee folded in, registration above renewal) adds the difference as
  a one-time line. A renewal invoice paid **before** its due date is charged once now and its subscription
  starts on the next due date, so renewals stay on WHMCS's due dates.
- **Discounted first period** (first-year domain price, promotion): this period's actual amount, with its tax,
  is a one-time line, and the item is a recurring line at its renewal price with `recurring.startsAt` = the
  day this period ends. A `PromoHosting` / `PromoDomain` line is counted with the item it discounts.
- **Start date source.** `startsAt` is the item's next due date after this invoice is paid:
  `tblhosting.nextduedate`, `tbldomains.nextduedate` or `tblhostingaddons.nextduedate`, plus one cycle (the
  domain's registration period). Until the invoice is paid WHMCS keeps that column on the first day of the
  period being paid, which equals the invoice line's `duedate` (for a new order or registration: the order
  date); on payment WHMCS moves it on by one cycle, to `startsAt`. If WHMCS already moved it on, the module
  uses it as is. If the dates disagree otherwise (a due date edited by hand), the period has already ended,
  or there is no due date, the discounted item is not guessed: Subscribe is not offered and the note says why.
  A month-end date clamps (31 Jan + 1 month = 28/29 Feb).
- **Not offered** when the invoice is part-paid or has credit applied (the lines no longer add up to the
  balance), has a negative line not tied to an item, or every recurring item already auto-renews or renews
  free; see the reasons above.
- WHMCS tax is included: exclusive tax is spread over the taxed lines and each renewal price carries its
  share at the same rate; PaymentHooks never adds tax on top.

The checkout session is created with `mode: "subscription"` and inline recurring line items. Hosting monthly
with a setup fee, a domain at a first-year price of 500.00 (renews at 800.00) and an addon:

```json
"lineItems": [
  { "description": "Starter Hosting", "quantity": 1, "unitPrice": 100000, "recurring": { "interval": "month", "intervalCount": 1 } },
  { "description": "Setup Fee",       "quantity": 1, "unitPrice": 15000 },
  { "description": "Domain Registration - example.com - 1 Year/s", "quantity": 1, "unitPrice": 80000,
    "recurring": { "interval": "year", "intervalCount": 1, "startsAt": "2027-11-07" } },
  { "description": "This period: Domain Registration - example.com - 1 Year/s", "quantity": 1, "unitPrice": 50000 },
  { "description": "Backups",         "quantity": 1, "unitPrice": 20000,  "recurring": { "interval": "month", "intervalCount": 1 } }
]
```

Charged now: 1,000.00 + 150.00 + 500.00 + 200.00 = the invoice balance. Then 1,200.00 every month from next
month (hosting + addon) and 800.00 every year from 2027-11-07 (domain).

The module checks the created session before sending the client to it, and refuses it
(`RECURRING_LINE_ITEMS_UNSUPPORTED`, Gateway Log `Checkout Error`, Activity Log `[checkout-click]`, nothing is
charged) when PaymentHooks did not keep a `recurring`, did not keep a `startsAt`, or returned no `subscriptions`
list for a checkout with more than one billing group. The client can still use Pay Now.

### Binding

On `checkout.session.completed` each subscription is bound to the items of its billing group, in
`mod_paymenthooks_subscriptions`, and its id is copied to the item's `subscriptionid` column where WHMCS has
one (always `tblhosting`; `tbldomains` and `tblhostingaddons` when your WHMCS version has it). Admins see it
on the service, and **Cancel Subscription** / cancelling the service cancels it at PaymentHooks.

With one group, the session's `subscription` is used. With several, the event's `subscriptions` list is
matched to the groups by billing cycle, start date (`starts_at`, else the next charge date) and, when listed,
the items and prices. A group is bound only to a single matching subscription; anything ambiguous or missing
is left unbound and the delivery is logged `Needs Attention` (`webhook-subscriptions-unmatched`) with what to
check. The payment itself is always recorded.

### Renewals

1. PaymentHooks charges the renewal and sends `subscription.renewed` and `invoice.paid` (the same payment id;
   the second is a `Duplicate`).
2. The module finds the oldest Unpaid invoice in the charge currency for each item **of that subscription
   only**: the monthly subscription pays the hosting renewal invoice, the yearly one the domain renewal
   invoice. One invoice for all of them, or one per item, both work; the charge is spread over them oldest
   first. When WHMCS put items of two subscriptions on one invoice (same due date), each subscription pays its
   own lines' share of that invoice, tax included.
3. **No renewal invoice yet.** If the item is due (its next due date is within 7 days), the module asks WHMCS
   to raise it now (`localAPI GenInvoices` for exactly those items, without emails), with WHMCS's own pricing,
   tax and numbering, then records the charge. If WHMCS still raises none, the event is redelivered
   (`Retry Requested`). If the item is not due (WHMCS already has the period paid, e.g. the client paid by
   hand), nothing is applied and the entry says to add credit or refund it (`Needs Attention`).
4. A charge that differs from the invoice balance (a price change in WHMCS, tax, credit) is recorded and
   flagged for review.

### Failed renewals and cancellation

- `subscription.renewal_failed`: nothing is charged from WHMCS. A note with the reason is added to the
  unpaid renewal invoice and the invoice stays Unpaid, so WHMCS's own overdue reminders, late fees and
  suspension run as configured. PaymentHooks retries on its dunning schedule; the client can also pay the
  invoice with Pay Now.
- `subscription.cancelled` / `subscription.deleted` (cancelled in PaymentHooks, by the client, or the mandate
  was revoked): every item is unbound and stops auto-renewing; WHMCS raises the next invoice for the client to
  pay or subscribe again.
- Cancelling or terminating a WHMCS service cancels its subscription at PaymentHooks immediately, for every
  item it renewed (the Activity Log lists them); an already-cancelled subscription counts as success.

Required WHMCS settings:

- Keep WHMCS's invoice generation on: renewal invoices are raised by WHMCS (or by the module on renewal, as
  above). Do not set up any other gateway's automatic capture on these clients.

| WHMCS cycle | PaymentHooks interval | intervalCount |
|-------------|-----------------------|---------------|
| Weekly | week | 1 |
| Monthly | month | 1 |
| Quarterly | month | 3 |
| Semi-Annually | month | 6 |
| Annually | year | 1 |
| Biennially | year | 2 |
| Triennially | year | 3 |
| Domain, N-year registration | year | N |

One Time and Free products are never subscriptions.

## Refunds

**Refund** on a PaymentHooks transaction in WHMCS reads the payment (`GET /payments/:id`) and calls
`POST /payments/:id/refund` with `transactionAmountUsdCents`: the amount, full or partial, converted to USD cents pro rata from what the
payment charged (`transactionLocalCurrencyAmount` against `transactionAmountUsdCents`). PaymentHooks then
refunds the gateway in the currency it charged. The refund is declined, with the reason in the Gateway Log,
when the WHMCS currency differs from the charged currency, when it exceeds what is left to refund, or when it
is under one US cent.

A refund made in the PaymentHooks dashboard (or API) arrives as `payment.refunded` and is **recorded on the
WHMCS invoice** against the original payment, without sending anything to a gateway. The event carries the
payment's total refunded so far; the module books only what WHMCS does not know yet, so refunds issued from
WHMCS (reserved before PaymentHooks is called), refunds already entered by hand and redeliveries are never
booked twice. WHMCS marks the invoice Refunded when nothing is left. A refund of the convenience fee (not
credited to the invoice) or in another currency is flagged `Needs Attention`.

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
| Renewal `Retry Requested` | The item is due but WHMCS raised no renewal invoice for it (check the product's invoice generation and that the item is Active). |
| No Subscribe button | With Enable Subscriptions on, the note under Pay Now says why, and the Activity Log has `PaymentHooks info [subscribe-unavailable]` with the reason code (or `[subscribe-plan]` when the invoice could not be read). No note: Enable Subscriptions is off. |
| `RECURRING_LINE_ITEMS_UNSUPPORTED` | Your PaymentHooks API did not keep `lineItems[].recurring`, `recurring.startsAt`, or return `subscriptions` for several billing groups; the entry says which. Clients can use Pay Now. |
| Completed checkout `Needs Attention` with `webhook-subscriptions-unmatched` | The payment is recorded but a billing group could not be matched to one subscription; check the subscriptions in PaymentHooks. |
| `Needs Attention` | Read the entry; it says what to record by hand. |

## Tests

Without WHMCS or a network (PHP 7.4+):

```
php tests/run.php              # signature, settings, amounts, line items, the checkout request, Subscribe plans, start dates, groups, notes, buttons, matching, refund USD cents, events, cycles
php tests/webhook-harness.php  # the invoice block, the Subscribe checkout and the full webhook path: invoices, bindings, renewals per subscription, refunds and the Gateway Log
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
| Subscribe & Auto-pay on hosting + addon | Service and addon show the subscription id; the next renewal is charged by PaymentHooks and marks the renewal invoice Paid |
| Hosting monthly + domain yearly | One checkout; the hosting and the domain each get their own subscription id; each renewal pays its own invoice |
| New domain at a first-year discount | Subscribe offered; first year charged once, the domain renews at full price from its next due date |
| Part-paid invoice | Only Pay Now, with the note under it |
| Renewal before WHMCS raised the invoice | The invoice is raised and paid; Gateway Log `Subscription Renewal Paid` |
| Failed renewal | The invoice stays Unpaid with a note; WHMCS reminders run |
| Refund in the PaymentHooks dashboard | Refund recorded on the WHMCS invoice |
| Cancel the service | Subscription cancelled in PaymentHooks for every item it renewed |

## License

MIT, see [LICENSE](LICENSE).
