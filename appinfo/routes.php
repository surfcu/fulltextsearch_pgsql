<?php

return [
    'routes' => [
        ['name' => 'settings#getConfig', 'url' => '/settings', 'verb' => 'GET'],
        ['name' => 'settings#setConfig', 'url' => '/settings', 'verb' => 'POST'],
    ]
];
