<?php
/**
 * Subscribe & Auto-pay: plans, start dates, grouping, the reason shown when
 * Subscribe is not offered, the summary, the platform-contract check,
 * subscription matching and renewal routing. Included by tests/run.php
 * (uses its check / same / throws / config helpers).
 */

use PaymentHooks\CheckoutBuilder;
use PaymentHooks\EventParser;
use PaymentHooks\InvoicePlan;
use PaymentHooks\PayButton;
use PaymentHooks\RecurringPlanner;
use PaymentHooks\RenewalInvoice;
use PaymentHooks\SubscriptionMatcher;

$today = '2026-11-07';
$ctx = ['today' => $today];
$relOf = function (array $items): array {
    return array_map(function ($i) { return [$i['relType'], $i['relId']]; }, $items);
};

// Invoice rows as InvoicePlan builds them (amounts incl. tax). New order placed today.
$hosting = ['type' => 'Hosting', 'relType' => 'hosting', 'relId' => 77, 'description' => 'Starter Hosting (example.com)', 'amountMinor' => 100000,
            'recurring' => ['interval' => 'month', 'intervalCount' => 1, 'renewalMinor' => 100000], 'subscribed' => false,
            'periodStart' => $today, 'lineDueDate' => $today];
$setup   = ['type' => 'Setup', 'relType' => '', 'relId' => 77, 'description' => 'Setup Fee', 'amountMinor' => 15000, 'recurring' => null, 'subscribed' => false];
$domain  = ['type' => 'DomainRegister', 'relType' => 'domain', 'relId' => 12, 'description' => 'Domain Registration - example.com - 1 Year/s', 'amountMinor' => 80000,
            'recurring' => ['interval' => 'year', 'intervalCount' => 1, 'renewalMinor' => 80000], 'subscribed' => false,
            'periodStart' => $today, 'lineDueDate' => $today];
$addon   = ['type' => 'Addon', 'relType' => 'addon', 'relId' => 5, 'description' => 'Backups', 'amountMinor' => 20000,
            'recurring' => ['interval' => 'month', 'intervalCount' => 1, 'renewalMinor' => 20000], 'subscribed' => false,
            'periodStart' => $today, 'lineDueDate' => $today];

echo "Dates\n";
same('add a month', '2026-12-07', RecurringPlanner::addCycle('2026-11-07', 'month', 1));
same('month end clamps (31 Jan + 1 month)', '2026-02-28', RecurringPlanner::addCycle('2026-01-31', 'month', 1));
same('leap day + 1 year', '2029-02-28', RecurringPlanner::addCycle('2028-02-29', 'year', 1));
same('quarterly', '2027-02-07', RecurringPlanner::addCycle('2026-11-07', 'month', 3));
same('weekly', '2026-11-14', RecurringPlanner::addCycle('2026-11-07', 'week', 1));
same('display date', '7 Nov 2027', RecurringPlanner::formatDate('2027-11-07'));
throws('not a date refused', function () { RecurringPlanner::addCycle('0000-00-00', 'year', 1); }, \InvalidArgumentException::class);

echo "Catalog identity (one product for every client, never the client's domain)\n";
same('.com registration', ['productKey' => 'com_domain_registration', 'productName' => '.com domain registration'],
    InvoicePlan::domainCatalog('DomainRegister', 'taptipm.com'));
same('.com renewal', ['productKey' => 'com_domain_renewal', 'productName' => '.com domain renewal'],
    InvoicePlan::domainCatalog('Domain', 'orivanuts.com'));
same('.co.uk transfer', ['productKey' => 'co_uk_domain_transfer', 'productName' => '.co.uk domain transfer'],
    InvoicePlan::domainCatalog('DomainTransfer', 'shop.co.uk'));
same('no domain: no key (PaymentHooks files it under its generic product)', [], InvoicePlan::domainCatalog('Domain', ''));
$keyed = RecurringPlanner::plan([$domain + ['productKey' => 'com_domain_registration', 'productName' => '.com domain registration']], 80000, $ctx);
same('recurring line carries the catalog key, the text stays the client\'s',
    ['description' => 'Domain Registration - example.com - 1 Year/s', 'productKey' => 'com_domain_registration', 'productName' => '.com domain registration'],
    ['description' => $keyed['lineItems'][0]['description'], 'productKey' => $keyed['lineItems'][0]['productKey'], 'productName' => $keyed['lineItems'][0]['productName']]);
$bad = RecurringPlanner::plan([$domain + ['productKey' => 'example.com renewal!']], 80000, $ctx);
same('an invalid key is not sent', false, isset($bad['lineItems'][0]['productKey']));
same('one-time lines carry no catalog key', false, isset(RecurringPlanner::plan([$hosting, $setup], 115000, $ctx)['lineItems'][1]['productKey']));

echo "Discounted first-year domain (live case: taptipm.com, 1,144.28 + 18% GST)\n";
// WHMCS line 1,144.28 incl. DNS Management + Email Forwarding, renewal 1,299.00, GST 205.97 => total 1,350.25.
$live = ['type' => 'DomainRegister', 'relType' => 'domain', 'relId' => 31, 'taxed' => true,
         'description' => 'Domain Registration - taptipm.com - 1 Year/s + DNS Management + Email Forwarding', 'amountMinor' => 114428,
         'recurring' => ['interval' => 'year', 'intervalCount' => 1, 'renewalMinor' => 129900], 'subscribed' => false,
         'periodStart' => $today, 'lineDueDate' => $today];
$taxed = InvoicePlan::allocateTax([$live], 20597, 135025);
same('tax allocated to the line', 135025, $taxed[0]['amountMinor']);
same('renewal carries the same tax rate', 129900 + 23382, $taxed[0]['recurring']['renewalMinor']);
$p = RecurringPlanner::plan($taxed, 135025, $ctx);
same('discounted domain: Subscribe offered', true, $p['offer']);
same('discounted domain: recurring line at the renewal price, starting when the paid year ends',
    ['unitPrice' => 153282, 'recurring' => ['interval' => 'year', 'intervalCount' => 1, 'startsAt' => '2027-11-07']],
    ['unitPrice' => $p['lineItems'][0]['unitPrice'], 'recurring' => $p['lineItems'][0]['recurring']]);
same('discounted domain: this year charged once at its actual amount', [135025, false], [$p['lineItems'][1]['unitPrice'], isset($p['lineItems'][1]['recurring'])]);
same('discounted domain: charged now = the balance', 135025, $p['nowMinor']);
same('discounted domain: one group from 2027-11-07', [1, '2027-11-07', '2027-11-07'], [count($p['groups']), $p['groups'][0]['startsAt'], $p['groups'][0]['firstRenewal']]);
same('WHMCS already moved the due date on (next due = period end): same start', '2027-11-07',
    RecurringPlanner::plan([['periodStart' => '2027-11-07'] + $taxed[0]], 135025, $ctx)['groups'][0]['startsAt'] ?? null);
$tpl = (string) file_get_contents(__DIR__ . '/../modules/gateways/paymenthooks/templates/pay_button.tpl');
$view = ['actionUrl' => 'https://billing.example.com/modules/gateways/callback/paymenthooks.php', 'invoiceId' => 1001, 'payText' => 'Pay Now', 'currency' => 'INR'];
$html = PayButton::render($tpl, $view + ['plan' => $p]);
check('discounted domain: Subscribe button shown', strpos($html, 'id="paymenthooks_pay_subscribe"') !== false);
check('summary: "1,350.25 now"', strpos($html, '<strong id="paymenthooks_pay_subscribe_now_amount">₹1,350.25</strong> now') !== false, $html);
check('summary: "then 1,532.82 every year from 7 Nov 2027"', strpos($html, '₹1,532.82 every year from 7 Nov 2027:') !== false, $html);
check('summary: the domain listed under its group', strpos($html, 'id="paymenthooks_pay_subscribe_group_0_item_0">Domain Registration - taptipm.com') !== false);
check('summary: this year listed as charged once, with when auto-pay starts', strpos($html, 'discounted price; auto-pay renews it from 7 Nov 2027') !== false, $html);
check('no unavailable note when offered', strpos($html, 'paymenthooks_pay_subscribe_unavailable') === false);

echo "Full price: the subscription's first charge pays this period\n";
$p = RecurringPlanner::plan([$domain], 80000, $ctx);
same('full-price domain: one recurring line, no startsAt', [['interval' => 'year', 'intervalCount' => 1]], array_column($p['lineItems'], 'recurring'));
same('full-price domain: renews a year from today', '2027-11-07', $p['groups'][0]['firstRenewal']);
$twoYear = ['recurring' => ['interval' => 'year', 'intervalCount' => 2, 'renewalMinor' => 160000], 'amountMinor' => 160000] + $domain;
same('2-year domain renews every 2 years', ['year', 2], [RecurringPlanner::plan([$twoYear], 160000, $ctx)['groups'][0]['interval'], RecurringPlanner::plan([$twoYear], 160000, $ctx)['groups'][0]['intervalCount']]);
$p = RecurringPlanner::plan([['amountMinor' => 120000] + $hosting], 120000, $ctx);
same('first period above renewal: renewal + one-time difference', [100000, 20000], array_column($p['lineItems'], 'unitPrice'));
$early = ['periodStart' => '2026-11-20', 'lineDueDate' => '2026-11-20'] + $hosting;
$p = RecurringPlanner::plan([$early], 100000, $ctx);
same('renewal invoice paid before its due date: this period once, subscription from the next due date',
    [100000, ['interval' => 'month', 'intervalCount' => 1, 'startsAt' => '2026-12-20'], 100000], [$p['lineItems'][0]['unitPrice'], $p['lineItems'][0]['recurring'], $p['lineItems'][1]['unitPrice']]);
$late = ['periodStart' => '2026-10-20', 'lineDueDate' => '2026-10-20'] + $hosting;
same('renewal invoice paid late: first charge now (as 3.1.0)', false, isset(RecurringPlanner::plan([$late], 100000, $ctx)['lineItems'][0]['recurring']['startsAt']));

echo "Mixed cycles in one checkout\n";
// Monthly hosting 1,000 + its 150 setup fee, yearly domain at a first-year price of 500 (renews 800), monthly addon 200.
$cheapDomain = ['amountMinor' => 50000] + $domain;
$p = RecurringPlanner::plan([$hosting, $setup, $cheapDomain, $addon], 185000, $ctx);
same('mixed: offered', true, $p['offer']);
same('mixed: two groups (monthly now, yearly from the domain\'s renewal)', [['month', 1, null], ['year', 1, '2027-11-07']],
    array_map(function ($g) { return [$g['interval'], $g['intervalCount'], $g['startsAt']]; }, $p['groups']));
same('mixed: hosting and addon in the monthly group', [['hosting', 77], ['addon', 5]], $relOf($p['groups'][0]['items']));
same('mixed: domain in the yearly group', [['domain', 12]], $relOf($p['groups'][1]['items']));
same('mixed: monthly group renews at 1,200.00, yearly at 800.00', [120000, 80000], array_column($p['groups'], 'renewalMinor'));
same('mixed: every item subscribed, nothing left to a later invoice', 3, count($p['subscribed']));
same('mixed: three recurring lines', 3, count(array_filter($p['lineItems'], function ($l) { return isset($l['recurring']); })));
same('mixed: charged now = hosting + setup + this domain year + addon', 185000, $p['nowMinor']);
same('mixed: one-time lines = setup fee and the domain year', [15000, 50000],
    array_values(array_column(array_filter($p['lineItems'], function ($l) { return !isset($l['recurring']); }), 'unitPrice')));
$b = CheckoutBuilder::build(config(), 9, 'INR', ['lineItems' => [], 'subtotal_minor' => 185000], 'subscription', 'https://b.example.com', '', '', $p);
same('mixed: one checkout carries every line', count($p['lineItems']), count($b['lineItems']));
same('mixed: metadata counts items and subscriptions', ['3', '2'], [$b['metadata']['whmcs_subscribed_items'], $b['metadata']['whmcs_subscriptions']]);
$html = PayButton::render($tpl, $view + ['plan' => $p]);
check('mixed summary: two groups', strpos($html, 'paymenthooks_pay_subscribe_group_1') !== false && strpos($html, 'paymenthooks_pay_subscribe_group_2') === false);
check('mixed summary: monthly group text', strpos($html, '₹1,200.00 every month from 7 Dec 2026:') !== false, $html);
check('mixed summary: yearly group text', strpos($html, '₹800.00 every year from 7 Nov 2027:') !== false, $html);
check('mixed summary: setup fee charged once', strpos($html, 'Setup Fee (₹150.00)') !== false);
preg_match_all('/<(div|ul|li|button|form|input|img|strong)\b(?![^>]*\bid=)[^>]*>/', $html, $noId);
same('every element has a paymenthooks_ id', [], $noId[0]);
$p = RecurringPlanner::plan([$hosting, $domain], 180000, $ctx);
same('full-price hosting + domain: two groups, both starting now', [null, null], array_column($p['groups'], 'startsAt'));
$p = RecurringPlanner::plan([['recurring' => ['interval' => 'month', 'intervalCount' => 12, 'renewalMinor' => 80000]] + $domain,
    ['recurring' => ['interval' => 'year', 'intervalCount' => 1, 'renewalMinor' => 20000]] + $addon], 100000, $ctx);
same('12 months and 1 year share one group', [1, 2], [count($p['groups']), count($p['groups'][0]['items'])]);

echo "Promotions\n";
$lines = [['type' => 'Hosting', 'relId' => 77, 'description' => 'Starter', 'amountMinor' => 100000],
          ['type' => 'PromoHosting', 'relId' => 77, 'description' => 'Promo: 50% off', 'amountMinor' => -50000],
          ['type' => 'Setup', 'relId' => 77, 'description' => 'Setup', 'amountMinor' => 15000]];
$net = InvoicePlan::netPromotions($lines);
same('promo line folded into its service', [50000, 15000], array_column($net, 'amountMinor'));
check('promo named on the service line', strpos($net[0]['description'], 'Promo: 50% off') !== false);
$p = RecurringPlanner::plan([['amountMinor' => 50000, 'description' => $net[0]['description']] + $hosting], 50000, $ctx);
same('promo month: once now, full price from next month', [50000, '2026-12-07'], [$p['nowMinor'], $p['groups'][0]['startsAt']]);
same('promo without its item stays a line', 2, count(InvoicePlan::netPromotions([$lines[1], $lines[2]])));

echo "Why Subscribe is not offered\n";
$reason = function (array $rows, int $balance, array $c = []) use ($ctx) {
    return RecurringPlanner::plan($rows, $balance, $c + $ctx)['reasonCode'];
};
same('part-paid', 'part_paid', $reason([$hosting, $setup], 100000, ['paidMinor' => 15000]));
same('credit applied', 'credit_applied', $reason([$hosting, $setup], 100000, ['creditMinor' => 15000]));
same('lines do not add up (no payment, no credit)', 'lines_mismatch', $reason([$hosting, $setup], 100000));
same('free renewal', 'free_renewal', $reason([['recurring' => ['interval' => 'year', 'intervalCount' => 1, 'renewalMinor' => 0]] + $domain], 80000));
same('already subscribed', 'already_subscribed', $reason([['subscribed' => true] + $hosting], 100000));
same('nothing recurring', 'nothing_recurring', $reason([$setup], 15000));
same('nothing due', 'no_balance', $reason([$hosting], 0));
same('negative line not tied to an item', 'negative_line', $reason([$hosting, ['type' => 'Item', 'relType' => '', 'relId' => 0, 'description' => 'Credit', 'amountMinor' => -10000, 'recurring' => null]], 90000));
same('discounted period already over', 'period_ended', $reason([['periodStart' => '2025-10-01', 'lineDueDate' => '2025-10-01'] + $cheapDomain], 50000));
same('discounted item whose due date was edited after invoicing', 'due_date_changed', $reason([['periodStart' => '2027-03-01'] + $cheapDomain], 50000));
same('discounted item without a due date', 'no_due_date', $reason([['periodStart' => '', 'lineDueDate' => ''] + $cheapDomain], 50000));
same('different reasons per item', 'mixed', $reason([['subscribed' => true] + $hosting, ['recurring' => ['interval' => 'year', 'intervalCount' => 1, 'renewalMinor' => 0]] + $domain], 180000));
$p = RecurringPlanner::plan([$hosting, $setup], 100000, ['paidMinor' => 15000] + $ctx);
same('not offered: no line items, groups or subscribed items', [false, [], [], []], [$p['offer'], $p['lineItems'], $p['groups'], $p['subscribed']]);
check('every reason code has client text', count(RecurringPlanner::REASONS) === 13 && !array_filter(RecurringPlanner::REASONS, function ($t) { return $t === '' || strpos($t, '[') !== false; }));
$note = function (array $v) use ($tpl, $view) {
    $h = PayButton::render($tpl, $view + $v);
    return preg_match('#<div class="paymenthooks-subscribe-unavailable" id="paymenthooks_pay_subscribe_unavailable" role="note"[^>]*>(.*?)</div>#', $h, $m) ? $m[1] : null;
};
same('Enable Subscriptions off (no plan read): no note at all', null, $note([]));
check('Enable Subscriptions off: no Subscribe button', strpos(PayButton::render($tpl, $view), 'paymenthooks_pay_subscribe') === false);
foreach (['part_paid' => ['paidMinor' => 15000], 'credit_applied' => ['creditMinor' => 15000]] as $code => $c) {
    same("note for {$code}", htmlspecialchars(RecurringPlanner::REASONS[$code], ENT_QUOTES), $note(['plan' => RecurringPlanner::plan([$hosting, $setup], 100000, $c + $ctx)]));
}
same('note for free renewal', RecurringPlanner::REASONS['free_renewal'],
    $note(['plan' => RecurringPlanner::plan([['recurring' => ['interval' => 'year', 'intervalCount' => 1, 'renewalMinor' => 0]] + $domain], 80000, $ctx)]));
same('note when the plan threw', PayButton::PLAN_ERROR_NOTE, $note(['planError' => true]));
check('note never shows the Subscribe button', substr_count(PayButton::render($tpl, $view + ['planError' => true]), 'type="submit"') === 1);
check('note has a prefixed id and no placeholder', strpos(PayButton::render($tpl, $view + ['planError' => true]), '{') === false);
same('money formatting', ['₹1,200.00', '$5.00', '1,500 JPY', '12.50 MYR'],
    [PayButton::money(120000, 'INR'), PayButton::money(500, 'USD'), PayButton::money(1500, 'JPY'), PayButton::money(1250, 'MYR')]);

echo "PaymentHooks kept the subscription lines (platform contract)\n";
$sent = [['description' => 'Domain', 'quantity' => 1, 'unitPrice' => 153282, 'recurring' => ['interval' => 'year', 'intervalCount' => 1, 'startsAt' => '2027-11-07']],
         ['description' => 'This period: Domain', 'quantity' => 1, 'unitPrice' => 135025]];
$echo = ['mode' => 'subscription', 'lineItems' => [
    ['description' => 'Domain', 'unitPrice' => 153282, 'recurring' => ['interval' => 'year', 'intervalCount' => 1, 'startsAt' => '2027-11-07T00:00:00.000Z']],
    ['description' => 'This period: Domain', 'unitPrice' => 135025]]];
same('startsAt echoed (ISO date-time) accepted', '', CheckoutBuilder::recurringProblem($sent, $echo));
$noStart = $echo;
unset($noStart['lineItems'][0]['recurring']['startsAt']);
check('API that drops startsAt refused, with why', strpos(CheckoutBuilder::recurringProblem($sent, $noStart), 'startsAt 2027-11-07 was not kept') !== false);
$shifted = $echo;
$shifted['lineItems'][0]['recurring']['startsAt'] = '2026-11-07';
check('API that moves startsAt refused', CheckoutBuilder::recurringProblem($sent, $shifted) !== '');
$two = [$sent[0], ['description' => 'Hosting', 'quantity' => 1, 'unitPrice' => 100000, 'recurring' => ['interval' => 'month', 'intervalCount' => 1]], $sent[1]];
$twoEcho = ['mode' => 'subscription', 'lineItems' => [$echo['lineItems'][0], ['recurring' => ['interval' => 'month', 'intervalCount' => 1]], $echo['lineItems'][1]]];
check('two groups without a subscriptions list refused', strpos(CheckoutBuilder::recurringProblem($two, $twoEcho), 'subscriptions') !== false);
same('two groups with a subscriptions list accepted', '', CheckoutBuilder::recurringProblem($two, $twoEcho + ['subscriptions' => []]));

echo "Subscriptions opened by one checkout, mapped to their items\n";
$p = RecurringPlanner::plan([$hosting, $setup, $cheapDomain, $addon], 185000, $ctx);
$ev = ['data' => ['object' => ['subscriptions' => [
    ['id' => 'ph_sub_year', 'interval' => 'year', 'interval_count' => 1, 'starts_at' => '2027-11-07', 'next_charge_at' => 1825545600,
     'items' => [['description' => 'Domain Registration - example.com - 1 Year/s', 'unit_amount' => 80000]]],
    ['id' => 'ph_sub_month', 'interval' => 'month', 'interval_count' => 1, 'starts_at' => null, 'current_period_end' => '2026-12-07T10:00:00Z'],
]]]];
$subs = EventParser::sessionSubscriptions(EventParser::object($ev));
same('subscriptions parsed', [['ph_sub_year', 'year', 1, true, '2027-11-07', '2027-11-07'], ['ph_sub_month', 'month', 1, true, null, '2026-12-07']],
    array_map(function ($s) { return [$s['id'], $s['interval'], $s['intervalCount'], $s['startsAtKnown'], $s['startsAt'], $s['nextCharge']]; }, $subs));
same('ids only (camelCase list) parsed', ['ph_sub_a', 'ph_sub_b'], array_column(EventParser::sessionSubscriptions(['subscriptions' => ['ph_sub_a', 'ph_sub_b']]), 'id'));
$m = SubscriptionMatcher::match($p['groups'], $subs);
same('each group mapped to its subscription', [0 => 'ph_sub_month', 1 => 'ph_sub_year'], $m['matched']);
same('nothing unmatched or unused', [[], []], [$m['unmatched'], $m['unused']]);
$byDate = [['id' => 'ph_sub_y', 'interval' => 'year', 'intervalCount' => 1, 'startsAtKnown' => false, 'startsAt' => null, 'nextCharge' => '2027-11-07', 'items' => []],
           ['id' => 'ph_sub_m', 'interval' => 'month', 'intervalCount' => 1, 'startsAtKnown' => false, 'startsAt' => null, 'nextCharge' => '', 'items' => []]];
same('matched by cadence and next charge date when startsAt is not echoed', [0 => 'ph_sub_m', 1 => 'ph_sub_y'], SubscriptionMatcher::match($p['groups'], $byDate)['matched']);
$twoYearly = RecurringPlanner::plan([$domain, ['relId' => 13, 'description' => 'Domain Registration - other.com - 1 Year/s', 'amountMinor' => 50000] + $domain], 130000, $ctx);
same('same cadence, different start: two groups', 2, count($twoYearly['groups']));
$ambiguous = [['id' => 'ph_1', 'interval' => 'year', 'intervalCount' => 1, 'startsAtKnown' => false, 'startsAt' => null, 'nextCharge' => '', 'items' => []],
              ['id' => 'ph_2', 'interval' => 'year', 'intervalCount' => 1, 'startsAtKnown' => false, 'startsAt' => null, 'nextCharge' => '', 'items' => []]];
same('two indistinguishable subscriptions: not guessed', [[], [0, 1]], [SubscriptionMatcher::match($twoYearly['groups'], $ambiguous)['matched'], SubscriptionMatcher::match($twoYearly['groups'], $ambiguous)['unmatched']]);
$wrongItems = $subs;
$wrongItems[0]['items'] = [['description' => 'Something else', 'unitPrice' => 80000]];
same('a subscription listing other items is not bound', [1], SubscriptionMatcher::match($p['groups'], $wrongItems)['unmatched']);

echo "Renewal routed to each subscription's own items\n";
$combined = [['type' => 'Hosting', 'relId' => 77, 'amountMinor' => 100000], ['type' => 'Addon', 'relId' => 5, 'amountMinor' => 20000],
             ['type' => 'Domain', 'relId' => 12, 'amountMinor' => 80000]];
same('monthly subscription pays its lines\' share (tax included) of a combined invoice', 141600,
    RenewalInvoice::share($combined, ['hosting:77', 'addon:5'], 236000, 236000));
same('yearly subscription pays the domain share', 94400, RenewalInvoice::share($combined, ['domain:12'], 236000, 236000));
same('share never exceeds the balance left', 50000, RenewalInvoice::share($combined, ['domain:12'], 236000, 50000));
same('invoice with only its items (and a late fee): the whole balance', 125000,
    RenewalInvoice::share([['type' => 'Hosting', 'relId' => 77, 'amountMinor' => 100000], ['type' => 'LateFee', 'relId' => 0, 'amountMinor' => 5000]], ['hosting:77'], 125000, 125000));
same('promo line counts for its item', 'hosting:77', RenewalInvoice::lineKey('PromoHosting', 77));
