<?php

return [
    'retailer' => [
        ['route' => 'retailer.dashboard', 'label' => 'Dashboard', 'icon' => 'grid'],
        ['route' => 'retailer.stock', 'label' => 'Le mie giacenze', 'icon' => 'box'],
        ['route' => 'retailer.sell', 'label' => 'Metti in vendita', 'icon' => 'plus'],
        ['route' => 'retailer.requests', 'label' => 'Richieste', 'icon' => 'chat'],
        ['route' => 'retailer.credit', 'label' => 'Credito', 'icon' => 'wallet'],
        ['route' => 'retailer.profile', 'label' => 'Profilo', 'icon' => 'user'],
    ],
    'admin' => [
        ['route' => 'admin.dashboard', 'label' => 'Dashboard', 'icon' => 'grid'],
        ['route' => 'admin.retailers', 'label' => 'Rivenditori', 'icon' => 'users'],
        ['route' => 'admin.stock', 'label' => 'Giacenze', 'icon' => 'box'],
        ['route' => 'admin.payouts', 'label' => 'Bonifici', 'icon' => 'wallet'],
        ['route' => 'admin.settings', 'label' => 'Impostazioni', 'icon' => 'settings'],
    ],
];
