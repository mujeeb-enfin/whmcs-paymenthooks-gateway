LOGO FILES — two locations, two purposes
=========================================

1. ADMIN side (Apps & Integrations / Payment Gateways list, shown on install):
       <package root>/logo.png        (beside whmcs.json)
   WHMCS reads whmcs.json and displays this image. Recommended width ~500px.

2. CLIENT side (above the "Pay Now" button on the invoice):
       modules/gateways/paymenthooks/assets/logo.png   (this folder)
   Shown unless a "Logo URL" is set in the gateway config (which wins).
   Recommended ~240x60px, transparent background.

The two can be the same image — just copy it into both locations.
No logo is bundled. If a file is absent, that side simply shows no image.
