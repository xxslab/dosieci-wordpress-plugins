<?php
/**
 * Uninstall handler for DoSieci WP Doctor.
 *
 * Nothing to remove: this build is read-only and stores no options, no
 * tables and no scan history at all. The file exists so the intent is
 * explicit rather than implied by its absence.
 *
 * @package DoSieci\WP\Doctor
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
