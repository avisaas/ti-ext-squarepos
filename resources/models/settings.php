<?php

use Igniter\Local\Models\Location;

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
                'sync_tasks' => [
                    'label' => 'lang:igniterlabs.squarepos::default.button_view_tasks',
                    'class' => 'btn btn-default',
                    'href' => 'igniterlabs/squarepos/tasks',
                ],
            ],
        ],
        'tabs' => [
            'fields' => [
                'source' => [
                    'tab' => 'igniterlabs.squarepos::default.text_tab_general',
                    'label' => 'igniterlabs.squarepos::default.label_system_record',
                    'type' => 'select',
                    'span' => 'left',
                    'comment' => 'igniterlabs.squarepos::default.help_system_record',
                ],
                'environment' => [
                    'tab' => 'igniterlabs.squarepos::default.text_tab_general',
                    'label' => 'igniterlabs.squarepos::default.label_environment',
                    'type' => 'select',
                    'span' => 'right',
                    'default' => 'production',
                    'comment' => 'igniterlabs.squarepos::default.help_environment',
                ],
                'application_id' => [
                    'tab' => 'igniterlabs.squarepos::default.text_tab_general',
                    'label' => 'igniterlabs.squarepos::default.label_application_id',
                    'type' => 'text',
                    'span' => 'left',
                    'comment' => 'igniterlabs.squarepos::default.help_application_id',
                    'trigger' => [
                        'action' => 'show',
                        'field' => 'environment',
                        'condition' => 'value[sandbox]',
                    ],
                ],
                'access_token' => [
                    'tab' => 'igniterlabs.squarepos::default.text_tab_general',
                    'label' => 'igniterlabs.squarepos::default.label_access_token',
                    'type' => 'text',
                    'span' => 'right',
                    'comment' => 'igniterlabs.squarepos::default.help_access_token',
                    'trigger' => [
                        'action' => 'show',
                        'field' => 'environment',
                        'condition' => 'value[sandbox]',
                    ],
                ],
                'connection_status' => [
                    'tab' => 'igniterlabs.squarepos::default.text_tab_general',
                    'label' => 'igniterlabs.squarepos::default.label_connection',
                    'type' => 'partial',
                    'path' => 'igniterlabs.squarepos::settings.field_connection',
                    'comment' => 'igniterlabs.squarepos::default.help_connection',
                    'trigger' => [
                        'action' => 'hide',
                        'field' => 'environment',
                        'condition' => 'value[sandbox]',
                    ],
                ],
                'sync_catalog' => [
                    'tab' => 'igniterlabs.squarepos::default.text_tab_general',
                    'label' => 'igniterlabs.squarepos::default.label_sync_catalog',
                    'type' => 'switch',
                    'default' => true,
                    'span' => 'left',
                    'comment' => 'igniterlabs.squarepos::default.help_sync_catalog',
                    'trigger' => [
                        'action' => 'show',
                        'field' => 'connection_status',
                        'condition' => 'value[1]',
                    ],
                ],
                'sync_interval' => [
                    'tab' => 'igniterlabs.squarepos::default.text_tab_general',
                    'label' => 'igniterlabs.squarepos::default.label_sync_interval',
                    'type' => 'select',
                    'span' => 'right',
                    'default' => 60,
                    'comment' => 'igniterlabs.squarepos::default.help_sync_interval',
                    'trigger' => [
                        'action' => 'show',
                        'field' => 'connection_status',
                        'condition' => 'value[1]',
                    ],
                ],
                'sync_inventory' => [
                    'tab' => 'igniterlabs.squarepos::default.text_tab_general',
                    'label' => 'igniterlabs.squarepos::default.label_sync_inventory',
                    'type' => 'switch',
                    'default' => true,
                    'span' => 'left',
                    'comment' => 'igniterlabs.squarepos::default.help_sync_inventory',
                    'trigger' => [
                        'action' => 'show',
                        'field' => 'connection_status',
                        'condition' => 'value[1]',
                    ],
                ],
                'inventory_interval' => [
                    'tab' => 'igniterlabs.squarepos::default.text_tab_general',
                    'label' => 'igniterlabs.squarepos::default.label_inventory_interval',
                    'type' => 'select',
                    'span' => 'right',
                    'default' => 60,
                    'comment' => 'igniterlabs.squarepos::default.help_inventory_interval',
                    'trigger' => [
                        'action' => 'show',
                        'field' => 'connection_status',
                        'condition' => 'value[1]',
                    ],
                ],
                'sync_orders' => [
                    'tab' => 'igniterlabs.squarepos::default.text_tab_general',
                    'label' => 'igniterlabs.squarepos::default.label_sync_orders',
                    'type' => 'switch',
                    'span' => 'left',
                    'default' => false,
                    'comment' => 'igniterlabs.squarepos::default.help_sync_orders',
                    'trigger' => [
                        'action' => 'show',
                        'field' => 'connection_status',
                        'condition' => 'value[1]',
                    ],
                ],
                'order_sync_interval' => [
                    'tab' => 'igniterlabs.squarepos::default.text_tab_general',
                    'label' => 'igniterlabs.squarepos::default.label_order_sync_interval',
                    'type' => 'select',
                    'span' => 'right',
                    'default' => 15,
                    'comment' => 'igniterlabs.squarepos::default.help_order_sync_interval',
                    'trigger' => [
                        'action' => 'show',
                        'field' => 'connection_status',
                        'condition' => 'value[1]',
                    ],
                ],
                'order_completed_status' => [
                    'tab' => 'igniterlabs.squarepos::default.text_tab_general',
                    'label' => 'igniterlabs.squarepos::default.label_order_completed_status',
                    'type' => 'select',
                    'span' => 'left',
                    'comment' => 'igniterlabs.squarepos::default.help_order_completed_status',
                    'trigger' => [
                        'action' => 'show',
                        'field' => 'sync_orders',
                        'condition' => 'checked',
                    ],
                ],
                'order_canceled_status' => [
                    'tab' => 'igniterlabs.squarepos::default.text_tab_general',
                    'label' => 'igniterlabs.squarepos::default.label_order_canceled_status',
                    'type' => 'select',
                    'span' => 'right',
                    'comment' => 'igniterlabs.squarepos::default.help_order_canceled_status',
                    'trigger' => [
                        'action' => 'show',
                        'field' => 'sync_orders',
                        'condition' => 'checked',
                    ],
                ],
                'handle_missing' => [
                    'tab' => 'igniterlabs.squarepos::default.text_tab_general',
                    'label' => 'igniterlabs.squarepos::default.label_handle_missing',
                    'type' => 'select',
                    'span' => 'left',
                    'default' => 'disable',
                    'comment' => 'igniterlabs.squarepos::default.help_handle_missing',
                    'trigger' => [
                        'action' => 'show',
                        'field' => 'connection_status',
                        'condition' => 'value[1]',
                    ],
                ],
                'sync_notify_email' => [
                    'tab' => 'igniterlabs.squarepos::default.text_tab_general',
                    'label' => 'igniterlabs.squarepos::default.label_sync_notify_email',
                    'type' => 'text',
                    'span' => 'right',
                    'comment' => 'igniterlabs.squarepos::default.help_sync_notify_email',
                    'trigger' => [
                        'action' => 'show',
                        'field' => 'connection_status',
                        'condition' => 'value[1]',
                    ],
                ],
                'import_items' => [
                    'tab' => 'igniterlabs.squarepos::default.text_tab_general',
                    'type' => 'partial',
                    'path' => 'igniterlabs.squarepos::settings.field_import_menus',
                    'trigger' => [
                        'action' => 'show',
                        'field' => 'connection_status',
                        'condition' => 'value[1]',
                    ],
                ],
                'export_items' => [
                    'tab' => 'igniterlabs.squarepos::default.text_tab_general',
                    'type' => 'partial',
                    'path' => 'igniterlabs.squarepos::settings.field_manual_export',
                    'trigger' => [
                        'action' => 'show',
                        'field' => 'connection_status',
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
                                'options' => [Location::class, 'getDropdownOptions'],
                            ],
                        ],
                    ],
                    'trigger' => [
                        'action' => 'show',
                        'field' => 'connection_status',
                        'condition' => 'value[1]',
                    ],
                ],
            ],
        ],
        'rules' => [
            'source' => 'required|in:square,igniter',
            'environment' => 'required|in:production,sandbox',
            'application_id' => 'nullable|string',
            'access_token' => 'nullable|string',
            'sync_catalog' => 'boolean',
            'sync_interval' => 'required|integer|in:15,30,60',
            'sync_inventory' => 'boolean',
            'inventory_interval' => 'required|integer|in:15,30,60',
            'handle_missing' => 'required|in:disable,archive,delete',
            'sync_notify_email' => 'nullable|email',
            'sync_orders' => 'boolean',
            'order_sync_interval' => 'nullable|integer|in:15,30,60',
            'order_completed_status' => 'nullable|integer',
            'order_canceled_status' => 'nullable|integer',
        ],
    ],
];
