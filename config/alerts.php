<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ventana de silenciamiento de una alerta gestionada
    |--------------------------------------------------------------------------
    |
    | Cuando la institución dueña del registro o el admin marca una alerta como
    | "gestionada / en seguimiento" (se coordinó un control fuera de la
    | plataforma), la alerta deja de contar como pendiente durante esta cantidad
    | de días. Pasado el plazo, si el problema persiste, la alerta vuelve a
    | aparecer como pendiente y hay que gestionarla de nuevo.
    |
    */

    'acknowledgement_ttl_days' => (int) env('ALERT_ACK_TTL_DAYS', 60),

    /*
    |--------------------------------------------------------------------------
    | Umbral de inasistencias
    |--------------------------------------------------------------------------
    |
    | Cantidad de inasistencias (en la foto vigente o en el último bimestre
    | informado) a partir de la cual se genera la alerta "Inasistencias
    | elevadas". El valor es "estrictamente mayor que".
    |
    */

    'absence_threshold' => (int) env('ALERT_ABSENCE_THRESHOLD', 10),

];
