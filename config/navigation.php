<?php

return [
    'customer' => [
        ['route' => 'customer.dashboard', 'label' => 'Marketplace', 'icon' => 'grid', 'pattern' => ['customer.dashboard', 'orders.*', 'customer.payments', 'customer.deliveries.*', 'cart.*', 'checkout.*'], 'children' => [['route' => 'orders.index', 'label' => 'I miei ordini', 'pattern' => 'orders.*'], ['route' => 'customer.payments', 'label' => 'Pagamenti'], ['route' => 'customer.deliveries.index', 'label' => 'Consegne', 'pattern' => 'customer.deliveries.*'], ['route' => 'cart.index', 'label' => 'Carrello']]],
        ['route' => 'customer.addresses.index', 'label' => 'Indirizzi', 'icon' => 'box', 'pattern' => 'customer.addresses.*'],
        ['route' => 'notifications.index', 'label' => 'Notifiche', 'icon' => 'chat', 'pattern' => 'notifications.*'],
        ['route' => 'customer.profile', 'label' => 'Profilo', 'icon' => 'user', 'pattern' => 'customer.profile*'],
    ],
    'retailer' => [
        ['route' => 'retailer.dashboard', 'label' => 'Dashboard', 'icon' => 'grid'],
        ['route' => 'retailer.sales.overview', 'label' => 'Vendite', 'icon' => 'box', 'pattern' => ['retailer.sales.*', 'retailer.stock*', 'retailer.sell', 'retailer.requests', 'retailer.credit'], 'requires_retailer' => true, 'children' => [
            ['route' => 'retailer.sales.index', 'label' => 'Ordini ricevuti', 'pattern' => 'retailer.sales.*'], ['route' => 'retailer.stock', 'label' => 'Le mie giacenze', 'requires_operate' => true], ['route' => 'retailer.sell', 'label' => 'Metti in vendita', 'requires_operate' => true], ['route' => 'retailer.requests', 'label' => 'Richieste', 'requires_operate' => true], ['route' => 'retailer.credit', 'label' => 'Credito', 'requires_operate' => true], ['route' => 'retailer.credit', 'label' => 'Richieste bonifico', 'fragment' => 'payouts-heading', 'requires_operate' => true],
        ]],
        ['route' => 'retailer.exchanges.index', 'label' => 'Scambi', 'icon' => 'chat', 'requires_retailer' => true, 'pattern' => ['retailer.exchanges.*', 'retailer.differences.*'], 'children' => [
            ['route' => 'retailer.exchanges.index', 'label' => 'Proposte ricevute', 'parameters' => ['tab' => 'received']], ['route' => 'retailer.exchanges.index', 'label' => 'Proposte inviate', 'parameters' => ['tab' => 'sent']], ['route' => 'retailer.differences.index', 'label' => 'Conguagli'],
        ]],
        ['route' => 'marketplace.index', 'label' => 'Vai al marketplace', 'icon' => 'grid', 'requires_operate' => true],
        ['route' => 'retailer.profile', 'label' => 'Profilo', 'icon' => 'user'],
    ],
    'admin' => [
        ['route' => 'admin.dashboard', 'label' => 'Dashboard', 'icon' => 'grid'],
        ['route' => 'admin.marketplace.overview', 'label' => 'Marketplace', 'icon' => 'box', 'pattern' => ['admin.marketplace.*', 'admin.categories.*'], 'children' => [
            ['route' => 'admin.categories.index', 'label' => 'Categorie', 'pattern' => 'admin.categories.*'],
            ...array_map(fn ($r, $label) => ['route' => 'admin.marketplace.index', 'label' => $label, 'parameters' => ['resource' => $r]], ['orders', 'vendors', 'exchanges', 'payments', 'refunds', 'commissions', 'reservations'], ['Ordini', 'Sottoordini', 'Scambi', 'Pagamenti', 'Rimborsi', 'Commissioni', 'Stock reservations']),
        ]],
        ['route' => 'admin.retailers', 'label' => 'Rivenditori', 'icon' => 'users', 'children' => [['route' => 'admin.stock', 'label' => 'Giacenze']]],
        ['route' => 'admin.payouts', 'label' => 'Bonifici', 'icon' => 'wallet'],
        ['route' => 'admin.settings', 'label' => 'Impostazioni', 'icon' => 'settings'],
    ],
];
