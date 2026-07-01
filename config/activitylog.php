<?php

return [
    /*
     * Activar o desactivar completamente el registro de actividad.
     * Útil para desactivar en tests sin modificar el código.
     */
    'enabled' => env('ACTIVITY_LOG_ENABLED', true),

    /*
     * Cantidad de días que se conservan los registros de actividad.
     * Pasado ese tiempo, pueden limpiarse con: php artisan activitylog:clean
     */
    'delete_records_older_than_days' => 365,

    /*
     * Nombre predeterminado del canal de log.
     * Cada módulo puede usar un nombre distinto (ej: 'auth', 'usuarios', 'instituciones').
     */
    'default_log_name' => 'sistema',

    /*
     * Modelo usado para guardar los registros de actividad.
     * Usa el modelo por defecto de Spatie.
     */
    'activity_model' => \Spatie\Activitylog\Models\Activity::class,

    /*
     * Nombre de la tabla en la base de datos.
     */
    'table_name' => env('ACTIVITY_LOG_TABLE', 'activity_log'),

    /*
     * Conexión de base de datos (null = usa la conexión por defecto del proyecto).
     */
    'database_connection' => env('ACTIVITY_LOG_DB_CONNECTION'),

    /*
     * Si es true, las consultas al log también incluyen registros borrados (soft delete).
     */
    'subject_returns_soft_deleted_models' => false,

    /*
     * Guard de autenticación para detectar automáticamente quién realiza la acción.
     * Usamos 'sanctum' porque la API se autentica con tokens de Sanctum.
     */
    'auth_driver' => env('ACTIVITY_LOG_AUTH_DRIVER', 'sanctum'),
];
