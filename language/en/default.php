<?php

return [
    'text_tab_general' => 'General',
    'text_tab_history' => 'Sync History',
    'text_connect' => 'Connect to Square POS',
    'text_disconnect' => 'Disconnect from Square POS',
    'text_square' => 'Square POS (overwrite TastyIgniter menu items)',
    'text_tastyigniter' => 'TastyIgniter (update Square catalog)',
    'text_success' => '<span class="text-success">Success</span>',
    'text_failed' => '<span class="text-danger">Failed</span>',

    'setting_title' => 'Square POS Settings',
    'setting_desc' => 'Configure settings to determine how data sync between Square and TastyIgniter.',

    'button_connect' => 'Connect with Square',
    'button_disconnect' => 'Disconnect from Square',
    'button_import_items' => 'Import Items',
    'button_manual_sync' => 'Sync Now',

    'label_connection' => 'Connection',
    'label_location' => 'Location Mappings',
    'label_remote_location' => 'Square Location',
    'label_client_location' => 'TastyIgniter Location',
    'label_system_record' => 'System of record',
    'label_sync_inventory' => 'Sync Inventory',
    'label_handle_missing' => 'Handle Missing Menu Items',
    'label_import_items' => 'Manually Import Items from Square',
    'label_enable_logging' => 'Log debug messages',
    'label_manual_sync' => 'Manually Sync Items & Inventory',

    'help_connection' => 'Connect or disconnect your site from Square.',
    'help_location' => 'Choose a location to link to this site. Only active locations which support credit card processing in Square can be linked',
    'help_system_record' => 'To ensure data integrity, choose the authoritative source for synced items. Square Inventory is always checked for changes when sync is enabled.',
    'help_sync_inventory' => 'Enable to fetch inventory changes from Square periodically and update in TastyIgniter.',
    'help_handle_missing' => 'Items removed in the Square will be disabled in TastyIgniter.',
    'help_enable_logging' => 'Save debug messages to the TastyIgniter System Log.',
];
