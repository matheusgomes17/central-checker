<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Convites
    |--------------------------------------------------------------------------
    |
    | O código do convite é a credencial que dá entrada no time, então ele
    | expira. Reenviar renova a janela a partir de agora — reenviar um convite
    | vencido sem renovar não resolveria nada.
    |
    | O intervalo mínimo entre reenvios existe porque o botão, sem ele, é um
    | canhão de e-mail apontado para um endereço fixo.
    |
    */

    'invitation_expires_days' => (int) env('TEAM_INVITATION_EXPIRES_DAYS', 3),

    'invitation_resend_cooldown_minutes' => (int) env('TEAM_INVITATION_RESEND_COOLDOWN_MINUTES', 5),

];
