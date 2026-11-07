<div class="paymenthooks-pay" id="paymenthooks_pay_container">
    {logo_html}
    {heading_html}
    {blurb_html}
    <form method="post" action="{action_url}" id="paymenthooks_pay_form" style="display:inline">
        <input type="hidden" name="action" value="checkout" id="paymenthooks_pay_action">
        <input type="hidden" name="invoice_id" value="{invoice_id}" id="paymenthooks_pay_invoice_id">
        <button type="submit" name="mode" value="payment" style="margin:5px !important" class="btn btn-primary" id="paymenthooks_pay_submit">{button_text}</button>
        {subscribe_html}
    </form>
    {summary_html}
</div>
