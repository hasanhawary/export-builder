<?php

return [
    // Export job messages
    'export_started_successfully' => 'Export started successfully.',
    'export_deleted_successfully' => 'Export deleted successfully.',
    'restored_successfully' => 'Record restored successfully.',
    'record_not_found' => 'Record not found.',
    'not_allowed_to_delete' => 'You are not allowed to delete this record.',
    'not_allowed_to_restore' => 'You are not allowed to restore this record.',
    'not_allowed_to_force' => 'You are not allowed to permanently delete this record.',
    'not_allowed_to_delete_linked' => 'This record cannot be deleted because it has linked records.',

    /*
    |--------------------------------------------------------------------------
    | Boolean values
    |--------------------------------------------------------------------------
    */
    'yes' => 'Yes',
    'no'  => 'No',

    /*
    |--------------------------------------------------------------------------
    | PDF view strings
    |--------------------------------------------------------------------------
    */
    'date_range'    => 'Date Range',
    'from'          => 'From',
    'to'            => 'To',
    'no_data'       => 'No data available',
    'generated_at'  => 'Generated at',
    'export_report' => 'Export Report',
    'total_records' => 'Total Records',
    'columns'       => 'Columns',
    'format'        => 'Format',
    'records'       => 'Records',

    /*
    |--------------------------------------------------------------------------
    | Common column headings
    | Keys are the snake_case column names used in BaseExport configs.
    | resolveTrans() looks these up automatically for heading labels.
    |--------------------------------------------------------------------------
    */
    'id'           => 'ID',
    'name'         => 'Name',
    'email'        => 'Email',
    'phone'        => 'Phone',
    'role'         => 'Role',
    'roles'        => 'Roles',
    'status'       => 'Status',
    'type'         => 'Type',
    'note'         => 'Note',

    // Boolean / active state
    'is_active'    => 'Active',
    'active'       => 'Active',

    // Dates
    'created_at'   => 'Created At',
    'updated_at'   => 'Updated At',
    'deleted_at'   => 'Deleted At',
    'started_at'   => 'Started At',
    'completed_at' => 'Completed At',
    'date'         => 'Date',
    'last_login'   => 'Last Login',

    // Ownership
    'creator'      => 'Creator',
    'created_by'   => 'Created By',

    // Gender
    'gender'       => 'Gender',
    'male'         => 'Male',
    'female'       => 'Female',

    // Status
    'pending'    => 'Pending',
    'processing' => 'Loading',
    'completed'  => 'Completed',
    'failed'     => 'Failed',
];
