<?php

$config['list']['filter'] = [
    'search' => [
        'prompt' => 'lang:igniterlabs.squarepos::default.text_filter_tasks',
        'mode' => 'all',
    ],
    'scopes' => [
        'status' => [
            'label' => 'lang:igniterlabs.squarepos::default.label_status',
            'type' => 'select',
            'conditions' => 'status = :filtered',
            'options' => [
                'queued' => 'igniterlabs.squarepos::default.label_status_queued',
                'running' => 'igniterlabs.squarepos::default.label_status_running',
                'success' => 'igniterlabs.squarepos::default.label_status_success',
                'failure' => 'igniterlabs.squarepos::default.label_status_failure',
            ],
        ],
    ],
];

$config['list']['toolbar'] = [
    'buttons' => [
        'settings' => [
            'label' => 'lang:igniterlabs.squarepos::default.setting_title',
            'class' => 'btn btn-default',
            'href' => 'extensions/edit/igniterlabs/squarepos/settings',
        ],
    ],
];

$config['list']['columns'] = [
    'preview' => [
        'type' => 'button',
        'iconCssClass' => 'fa fa-eye',
        'attributes' => [
            'class' => 'btn btn-edit',
            'href' => 'igniterlabs/squarepos/tasks/preview/{id}',
        ],
    ],
    'description' => [
        'label' => 'lang:igniterlabs.squarepos::default.column_description',
        'type' => 'text',
    ],
    'status_name' => [
        'label' => 'lang:igniterlabs.squarepos::default.label_status',
        'type' => 'partial',
        'path' => 'igniterlabs.squarepos::tasks.status_column',
        'sortable' => false,
    ],
    'started_at' => [
        'label' => 'lang:igniterlabs.squarepos::default.column_started_at',
        'type' => 'datetime',
    ],
    'finished_at' => [
        'label' => 'lang:igniterlabs.squarepos::default.column_finished_at',
        'type' => 'datetime',
    ],
    'created_at' => [
        'label' => 'lang:igniterlabs.squarepos::default.column_ran_at',
        'type' => 'timetense',
    ],
];

$config['form']['fields'] = [
    'created_at' => [
        'label' => 'lang:igniterlabs.squarepos::default.column_ran_at',
        'type' => 'text',
        'span' => 'left',
        'disabled' => true,
    ],
    'description' => [
        'label' => 'lang:igniterlabs.squarepos::default.column_description',
        'type' => 'text',
        'span' => 'right',
        'disabled' => true,
    ],
    'started_at' => [
        'label' => 'lang:igniterlabs.squarepos::default.column_started_at',
        'type' => 'text',
        'span' => 'left',
        'disabled' => true,
    ],
    'finished_at' => [
        'label' => 'lang:igniterlabs.squarepos::default.column_finished_at',
        'type' => 'text',
        'span' => 'right',
        'disabled' => true,
    ],
    'status_name' => [
        'label' => 'lang:igniterlabs.squarepos::default.label_status',
        'type' => 'partial',
        'path' => 'igniterlabs.squarepos::tasks.status_column',
    ],
    'notes' => [
        'label' => 'lang:igniterlabs.squarepos::default.column_notes',
        'type' => 'partial',
        'path' => 'igniterlabs.squarepos::tasks.field_notes',
    ],
    'exception' => [
        'label' => 'lang:igniterlabs.squarepos::default.column_exception',
        'type' => 'partial',
        'path' => 'igniterlabs.squarepos::tasks.field_exception',
    ],
];

return $config;
