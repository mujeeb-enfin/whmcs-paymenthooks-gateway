<div class="paymenthooks-pay">
    {logo_html}
    {heading_html}
    {blurb_html}
    <form method="post" action="{action_url}" style="display:inline">
        <input type="hidden" name="action" value="checkout">
        <input type="hidden" name="invoice_id" value="{invoice_id}">
        <input type="submit" value="{button_text}" class="btn btn-primary">
    </form>
</div>
