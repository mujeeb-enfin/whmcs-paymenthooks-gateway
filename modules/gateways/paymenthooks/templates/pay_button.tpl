<div class="paymenthooks-pay" id="paymenthooks_pay_container">
    {logo_html}
    {heading_html}
    {blurb_html}
    <form method="post" action="{action_url}" id="paymenthooks_pay_form" style="display:inline">
        <input type="hidden" name="action" value="checkout" id="paymenthooks_pay_action">
        <input type="hidden" name="invoice_id" value="{invoice_id}" id="paymenthooks_pay_invoice_id">
        <input type="submit" value="{button_text}" class="btn btn-primary" id="paymenthooks_pay_submit">
    </form>
</div>
