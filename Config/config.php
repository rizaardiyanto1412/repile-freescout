<?php

return [
    'name' => 'Repile',
    'options' => [
        'repile.url' => ['default' => ''],
        'repile.webhook_secret' => ['default' => ''],
        'repile.api_key' => ['default' => ''],
        'repile.bot_user_id' => ['default' => ''],
        'repile.last_delivery' => ['default' => ''],
        'repile.mailbox_ids' => ['default' => []],
        'repile.redact_credentials' => ['default' => false],
        'repile.exclude_notes' => ['default' => false],
        'repile.allow_private_network' => ['default' => false],
    ],
];
