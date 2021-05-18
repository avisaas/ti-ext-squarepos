<?php

/**
 * Model configuration options for settings model.
 */

return [
    'form' => [
        'toolbar' => [
            'buttons' => [
                'save' => [
                    'label' => 'lang:admin::lang.button_save',
                    'class' => 'btn btn-primary',
                    'data-request' => 'onSave',
                ],
                'saveClose' => [
                    'label' => 'lang:admin::lang.button_save_close',
                    'class' => 'btn btn-default',
                    'data-request' => 'onSave',
                    'data-request-data' => 'close:1',
                ],
            ],
        ],
        'tabs' => [
            'fields' => [
                'connection' => [
                    'tab' => 'igniterlabs.squarepos::default.text_tab_general',
                    'label' => 'igniterlabs.squarepos::default.label_connection',
                    'type' => 'partial',
                    'path' => '$/igniterlabs/squarepos/views/settings/field_connection',
                    'comment' => 'igniterlabs.squarepos::default.help_connection',
                ],
                'source' => [
                    'tab' => 'igniterlabs.squarepos::default.text_tab_general',
                    'label' => 'igniterlabs.squarepos::default.label_system_record',
                    'type' => 'select',
                    'span' => 'left',
                    'comment' => 'igniterlabs.squarepos::default.help_system_record',
                ],
                'sync_inventory' => [
                    'tab' => 'igniterlabs.squarepos::default.text_tab_general',
                    'label' => 'igniterlabs.squarepos::default.label_sync_inventory',
                    'type' => 'switch',
                    'default' => TRUE,
                    'comment' => 'igniterlabs.squarepos::default.help_sync_inventory',
                ],
                'location_mappings' => [
                    'tab' => 'igniterlabs.squarepos::default.text_tab_general',
                    'label' => 'igniterlabs.squarepos::default.label_location',
                    'type' => 'repeater',
                    'span' => 'left',
                    'commentAbove' => 'igniterlabs.squarepos::default.help_location',
                    'form' => [
                        'fields' => [
                            'remote_id' => [
                                'label' => 'igniterlabs.squarepos::default.label_remote_location',
                                'type' => 'select',
                                'span' => 'left',
                            ],
                            'client_id' => [
                                'label' => 'igniterlabs.squarepos::default.label_client_location',
                                'type' => 'select',
                                'options' => [\Admin\Models\Locations_model::class, 'getDropdownOptions'],
                            ],
                        ],
                    ],
                ],
                'import_menus' => [
                    'tab' => 'igniterlabs.squarepos::default.text_tab_general',
                    'label' => 'igniterlabs.squarepos::default.label_import_items',
                    'type' => 'partial',
                    'path' => '$/igniterlabs/squarepos/views/settings/field_import_menus',
                ],
                'enable_logging' => [
                    'tab' => 'igniterlabs.squarepos::default.text_tab_general',
                    'label' => 'igniterlabs.squarepos::default.label_enable_logging',
                    'type' => 'switch',
                    'default' => FALSE,
                    'comment' => 'igniterlabs.squarepos::default.help_enable_logging',
                ],

                'manual_sync' => [
                    'tab' => 'igniterlabs.squarepos::default.text_tab_history',
                    'label' => 'igniterlabs.squarepos::default.label_manual_sync',
                    'type' => 'partial',
                    'path' => '$/igniterlabs/squarepos/views/settings/field_manual_sync',
                ],
            ],
        ],
    ],
];
