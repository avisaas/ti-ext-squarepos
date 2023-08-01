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
                    'data-progress-indicator' => 'admin::lang.text_saving',
                ],
                'manual_sync' => [
                    'label' => 'lang:igniterlabs.squarepos::default.button_manual_sync',
                    'class' => 'btn btn-default',
                    'href' => 'igniterlabs/squarepos/merchant/sync',
                ],
            ],
        ],
        'tabs' => [
            'fields' => [
                'connection_data[status]' => [
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
                    'trigger' => [
                        'action' => 'show',
                        'field' => 'connection_data[status]',
                        'condition' => 'value[1]',
                    ],
                ],
                'sync_inventory' => [
                    'tab' => 'igniterlabs.squarepos::default.text_tab_general',
                    'label' => 'igniterlabs.squarepos::default.label_sync_inventory',
                    'type' => 'switch',
                    'default' => true,
                    'comment' => 'igniterlabs.squarepos::default.help_sync_inventory',
                    'trigger' => [
                        'action' => 'show',
                        'field' => 'connection_data[status]',
                        'condition' => 'value[1]',
                    ],
                ],

                'location_mappings' => [
                    'tab' => 'igniterlabs.squarepos::default.text_tab_location',
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
                                'options' => [\Igniter\Local\Models\Location::class, 'getDropdownOptions'],
                            ],
                        ],
                    ],
                    'trigger' => [
                        'action' => 'show',
                        'field' => 'connection_data[status]',
                        'condition' => 'value[1]',
                    ],
                ],
            ],
        ],
    ],
];
