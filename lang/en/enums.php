<?php

return [
    'role' => ['admin' => 'Administrator', 'staff' => 'Cleaning staff'],
    'employment_type' => [
        'casual' => 'Casual',
        'part_time' => 'Part-time',
        'full_time' => 'Full-time',
        'contractor_abn' => 'Contractor (ABN)',
    ],
    'pricing_model' => ['fixed' => 'Fixed price per visit', 'hourly' => 'Hourly'],
    'payment_method' => ['cash' => 'Cash', 'abn' => 'ABN / bank transfer'],
    'frequency' => [
        'weekly' => 'Weekly',
        'fortnightly' => 'Fortnightly',
        'every_3_weeks' => 'Every 3 weeks',
        'every_4_weeks' => 'Every 4 weeks',
        'monthly_nth_weekday' => 'Monthly',
    ],
    'appointment_status' => [
        'scheduled' => 'Scheduled',
        'in_progress' => 'In progress',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        'cancelled_late' => 'Late cancellation',
        'no_access' => 'No access',
        'invoiced' => 'Invoiced',
    ],
];
