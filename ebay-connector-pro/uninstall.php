<?php
/**
 * Uninstall handler.
 *
 * Uruchamiany wyłącznie przy jawnym usunięciu wtyczki przez administratora
 * (nigdy przy zwykłej deaktywacji). Faza 1+: zapytać użytkownika (przez
 * osobny ekran potwierdzenia przed usunięciem) czy zachować czy usunąć dane
 * — zgodnie z sekcją 7 master promptu ("bezpieczny uninstall i opcja
 * zachowania/usunięcia danych").
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Faza 1+: usuwanie danych tylko po jawnej zgodzie zapisanej wcześniej w opcji `*_delete_data_on_uninstall`.
