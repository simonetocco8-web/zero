@props(['retailer' => null])
<div class="grid min-w-0 gap-5 sm:grid-cols-2">
<x-field name="company_name" label="Ragione sociale" :value="$retailer?->company_name" required />
<x-field name="vat_number" label="Partita IVA" :value="$retailer?->vat_number" required />
<x-field name="city" label="Città" :value="$retailer?->city" required />
<x-field name="contact_name" label="Referente" :value="$retailer?->contact_name" />
<x-field name="contact_email" label="Email contatto" :value="$retailer?->contact_email" type="email" />
<x-field name="phone" label="Telefono" :value="$retailer?->phone" />
<x-field name="address" label="Indirizzo" :value="$retailer?->address" />
<x-field name="province" label="Provincia" :value="$retailer?->province" />
<x-field name="region" label="Regione" :value="$retailer?->region" />
<x-field name="website" label="Sito web" :value="$retailer?->website" type="url" />
</div>