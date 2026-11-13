<?php

return [
    'role' => ['admin' => 'Administrador/a', 'staff' => 'Personal de limpieza'],
    'employment_type' => [
        'casual' => 'Casual',
        'part_time' => 'Medio tiempo',
        'full_time' => 'Tiempo completo',
        'contractor_abn' => 'Contratista (ABN)',
    ],
    'pricing_model' => ['fixed' => 'Precio fijo por visita', 'hourly' => 'Por hora'],
    'payment_method' => ['cash' => 'Efectivo', 'abn' => 'ABN / transferencia'],
    'frequency' => [
        'weekly' => 'Semanal',
        'fortnightly' => 'Quincenal',
        'every_3_weeks' => 'Cada 3 semanas',
        'every_4_weeks' => 'Cada 4 semanas',
        'monthly_nth_weekday' => 'Mensual',
    ],
    'appointment_status' => [
        'scheduled' => 'Programada',
        'in_progress' => 'En curso',
        'completed' => 'Completada',
        'cancelled' => 'Cancelada',
        'cancelled_late' => 'Cancelación tardía',
        'no_access' => 'Sin acceso',
        'invoiced' => 'Facturada',
    ],
    'time_entry_status' => [
        'running' => 'En curso',
        'stopped' => 'Sin enviar',
        'submitted' => 'Enviada',
        'approved' => 'Aprobada',
        'rejected' => 'Devuelta',
        'invoiced' => 'Facturada',
    ],
];
