<?php

declare(strict_types=1);

namespace DoSieci\WP\Doctor\Domain;

/**
 * The free tier's diagnostic engine: pure, read-only analysis of SiteFacts.
 *
 * There is no "fix" method anywhere in this class, and that is the whole
 * design. The legacy plugin this product replaces (audit/ai-wp-doctor-mvp-AUDIT.md)
 * shipped an Autofix that ran unconditional DELETEs and OPTIMIZE TABLE in a
 * loop from a single AJAX call, with no preflight, dry-run or backup. The
 * free build here reports and explains; anything that writes belongs to a
 * separate, gated flow (eligibility -> preflight -> dry-run -> backup ->
 * apply -> verify -> rollback) and is deliberately not part of this tier.
 */
final class DiagnosticEngine {

	// Thresholds. Sourced from WordPress's own published requirements and
	// the widely-used 800 KB autoload guideline rather than invented.
	public const MIN_SUPPORTED_PHP        = '8.1';
	public const RECOMMENDED_PHP          = '8.2';
	public const AUTOLOAD_WARNING_BYTES   = 800 * 1024;
	public const AUTOLOAD_CRITICAL_BYTES  = 2 * 1024 * 1024;
	public const REVISION_WARNING_RATIO   = 10;
	public const TRANSIENT_WARNING_COUNT  = 2000;
	public const MEMORY_WARNING_BYTES     = 128 * 1024 * 1024;
	public const CRON_OVERDUE_SECONDS     = 3600;

	/** @return CheckResult[] */
	public function run( SiteFacts $facts ): array {
		return array(
			$this->checkPhpVersion( $facts ),
			$this->checkHttps( $facts ),
			$this->checkDebugSettings( $facts ),
			$this->checkSearchEngineVisibility( $facts ),
			$this->checkPermalinks( $facts ),
			$this->checkAutoloadedOptions( $facts ),
			$this->checkRevisions( $facts ),
			$this->checkTransients( $facts ),
			$this->checkMemoryLimit( $facts ),
			$this->checkCronBacklog( $facts ),
			$this->checkInactivePlugins( $facts ),
		);
	}

	/**
	 * @param CheckResult[] $results
	 *
	 * @return array{good:int, warning:int, critical:int, info:int, score:int}
	 */
	public function summarise( array $results ): array {
		$counts = array( 'good' => 0, 'warning' => 0, 'critical' => 0, 'info' => 0 );

		foreach ( $results as $result ) {
			++$counts[ $result->status ];
		}

		$scored = $counts['good'] + $counts['warning'] + $counts['critical'];

		// A deliberately blunt score: it exists to show direction of travel
		// between scans, NOT to be advertised as "your site is X% secure".
		// PRODUCT_SCOPE.md explicitly forbids promising that a score equals
		// a safe site.
		$counts['score'] = $scored > 0
			? (int) round( ( ( $counts['good'] + $counts['warning'] * 0.5 ) / $scored ) * 100 )
			: 100;

		return $counts;
	}

	private function checkPhpVersion( SiteFacts $facts ): CheckResult {
		if ( version_compare( $facts->phpVersion, self::MIN_SUPPORTED_PHP, '<' ) ) {
			return new CheckResult(
				'php_version',
				'Wersja PHP',
				CheckResult::STATUS_CRITICAL,
				sprintf( 'PHP %s nie otrzymuje już poprawek bezpieczeństwa.', $facts->phpVersion ),
				sprintf( 'Poproś hosting o aktualizację do PHP %s lub nowszego.', self::RECOMMENDED_PHP ),
				array( 'current' => $facts->phpVersion )
			);
		}

		if ( version_compare( $facts->phpVersion, self::RECOMMENDED_PHP, '<' ) ) {
			return new CheckResult(
				'php_version',
				'Wersja PHP',
				CheckResult::STATUS_WARNING,
				sprintf( 'PHP %s działa, ale nie jest to wersja zalecana.', $facts->phpVersion ),
				sprintf( 'Rozważ aktualizację do PHP %s.', self::RECOMMENDED_PHP ),
				array( 'current' => $facts->phpVersion )
			);
		}

		return new CheckResult(
			'php_version',
			'Wersja PHP',
			CheckResult::STATUS_GOOD,
			sprintf( 'PHP %s jest aktualnie wspierane.', $facts->phpVersion ),
			'',
			array( 'current' => $facts->phpVersion )
		);
	}

	private function checkHttps( SiteFacts $facts ): CheckResult {
		return $facts->isHttps
			? new CheckResult( 'https', 'HTTPS', CheckResult::STATUS_GOOD, 'Witryna działa po HTTPS.' )
			: new CheckResult(
				'https',
				'HTTPS',
				CheckResult::STATUS_CRITICAL,
				'Witryna nie używa HTTPS.',
				'Włącz certyfikat SSL (np. Let\'s Encrypt) i przestaw adres witryny na https://.'
			);
	}

	private function checkDebugSettings( SiteFacts $facts ): CheckResult {
		if ( $facts->debugEnabled && $facts->debugDisplayEnabled ) {
			return new CheckResult(
				'debug',
				'Tryb debugowania',
				CheckResult::STATUS_CRITICAL,
				'WP_DEBUG_DISPLAY jest włączone — komunikaty błędów mogą być widoczne dla odwiedzających.',
				'Ustaw WP_DEBUG_DISPLAY na false w wp-config.php. Błędy loguj do pliku, nie na ekran.'
			);
		}

		if ( $facts->debugEnabled ) {
			return new CheckResult(
				'debug',
				'Tryb debugowania',
				CheckResult::STATUS_WARNING,
				'WP_DEBUG jest włączone (bez wyświetlania na ekranie).',
				'Na produkcji zwykle powinno być wyłączone.'
			);
		}

		return new CheckResult( 'debug', 'Tryb debugowania', CheckResult::STATUS_GOOD, 'Debugowanie wyłączone.' );
	}

	private function checkSearchEngineVisibility( SiteFacts $facts ): CheckResult {
		return $facts->searchEngineDiscouraged
			? new CheckResult(
				'search_visibility',
				'Widoczność w wyszukiwarkach',
				CheckResult::STATUS_CRITICAL,
				'Witryna prosi wyszukiwarki o nieindeksowanie.',
				'Odznacz „Proś wyszukiwarki o nieindeksowanie tej witryny” w Ustawienia → Czytanie — o ile to nie jest celowe (np. staging).'
			)
			: new CheckResult( 'search_visibility', 'Widoczność w wyszukiwarkach', CheckResult::STATUS_GOOD, 'Witryna jest indeksowalna.' );
	}

	private function checkPermalinks( SiteFacts $facts ): CheckResult {
		return '' === $facts->permalinkStructure
			? new CheckResult(
				'permalinks',
				'Struktura bezpośrednich odnośników',
				CheckResult::STATUS_WARNING,
				'Używane są domyślne odnośniki (?p=123).',
				'Przełącz na strukturę opartą na nazwie wpisu w Ustawienia → Bezpośrednie odnośniki.'
			)
			: new CheckResult( 'permalinks', 'Struktura bezpośrednich odnośników', CheckResult::STATUS_GOOD, 'Używana jest czytelna struktura odnośników.' );
	}

	private function checkAutoloadedOptions( SiteFacts $facts ): CheckResult {
		$kb = (int) round( $facts->autoloadedBytes / 1024 );

		if ( $facts->autoloadedBytes >= self::AUTOLOAD_CRITICAL_BYTES ) {
			return new CheckResult(
				'autoload',
				'Autoładowane opcje',
				CheckResult::STATUS_CRITICAL,
				sprintf( '%d KB opcji ładuje się przy każdym żądaniu.', $kb ),
				'Znajdź największe wpisy autoload i wyłącz autoload tam, gdzie nie jest potrzebny. Zwykle winne są nieużywane wtyczki i porzucone transienty.',
				array( 'bytes' => $facts->autoloadedBytes )
			);
		}

		if ( $facts->autoloadedBytes >= self::AUTOLOAD_WARNING_BYTES ) {
			return new CheckResult(
				'autoload',
				'Autoładowane opcje',
				CheckResult::STATUS_WARNING,
				sprintf( '%d KB opcji ładuje się przy każdym żądaniu.', $kb ),
				'Zalecany limit to ok. 800 KB. Sprawdź największe wpisy.',
				array( 'bytes' => $facts->autoloadedBytes )
			);
		}

		return new CheckResult(
			'autoload',
			'Autoładowane opcje',
			CheckResult::STATUS_GOOD,
			sprintf( '%d KB — poniżej zalecanego progu.', $kb ),
			'',
			array( 'bytes' => $facts->autoloadedBytes )
		);
	}

	private function checkRevisions( SiteFacts $facts ): CheckResult {
		if ( 0 === $facts->postCount ) {
			return new CheckResult( 'revisions', 'Rewizje wpisów', CheckResult::STATUS_INFO, 'Brak wpisów do oceny.' );
		}

		$ratio = $facts->revisionCount / max( 1, $facts->postCount );

		if ( $ratio >= self::REVISION_WARNING_RATIO ) {
			return new CheckResult(
				'revisions',
				'Rewizje wpisów',
				CheckResult::STATUS_WARNING,
				sprintf( '%d rewizji na %d wpisów (średnio %.1f na wpis).', $facts->revisionCount, $facts->postCount, $ratio ),
				'Rozważ ograniczenie WP_POST_REVISIONS. Nie usuwaj rewizji hurtowo bez kopii zapasowej — to operacja nieodwracalna.',
				array( 'revisions' => $facts->revisionCount, 'posts' => $facts->postCount )
			);
		}

		return new CheckResult(
			'revisions',
			'Rewizje wpisów',
			CheckResult::STATUS_GOOD,
			sprintf( '%d rewizji na %d wpisów.', $facts->revisionCount, $facts->postCount )
		);
	}

	private function checkTransients( SiteFacts $facts ): CheckResult {
		return $facts->transientCount >= self::TRANSIENT_WARNING_COUNT
			? new CheckResult(
				'transients',
				'Transienty',
				CheckResult::STATUS_WARNING,
				sprintf( 'W bazie jest %d transientów.', $facts->transientCount ),
				'Duża liczba transientów zwykle oznacza brak obiektowego cache. Rozważ Redis/Memcached zamiast czyszczenia ich ręcznie.',
				array( 'count' => $facts->transientCount )
			)
			: new CheckResult( 'transients', 'Transienty', CheckResult::STATUS_GOOD, sprintf( '%d transientów.', $facts->transientCount ) );
	}

	private function checkMemoryLimit( SiteFacts $facts ): CheckResult {
		return $facts->memoryLimitBytes > 0 && $facts->memoryLimitBytes < self::MEMORY_WARNING_BYTES
			? new CheckResult(
				'memory_limit',
				'Limit pamięci PHP',
				CheckResult::STATUS_WARNING,
				sprintf( 'Limit pamięci to %d MB.', (int) round( $facts->memoryLimitBytes / 1048576 ) ),
				'Dla WooCommerce zalecane jest co najmniej 128 MB (często 256 MB).',
				array( 'bytes' => $facts->memoryLimitBytes )
			)
			: new CheckResult( 'memory_limit', 'Limit pamięci PHP', CheckResult::STATUS_GOOD, 'Limit pamięci jest wystarczający.' );
	}

	private function checkCronBacklog( SiteFacts $facts ): CheckResult {
		$overdue = array_filter(
			$facts->cronEvents,
			static fn( array $event ): bool => $event['timestamp'] < ( $facts->now - self::CRON_OVERDUE_SECONDS )
		);

		return array() !== $overdue
			? new CheckResult(
				'cron',
				'Zadania cron',
				CheckResult::STATUS_WARNING,
				sprintf( '%d zadań zaległych o ponad godzinę.', count( $overdue ) ),
				'WP-Cron uruchamia się przy ruchu na witrynie. Przy małym ruchu skonfiguruj systemowy cron wywołujący wp-cron.php.',
				array( 'overdue' => count( $overdue ), 'total' => count( $facts->cronEvents ) )
			)
			: new CheckResult( 'cron', 'Zadania cron', CheckResult::STATUS_GOOD, sprintf( '%d zaplanowanych zadań, brak zaległości.', count( $facts->cronEvents ) ) );
	}

	private function checkInactivePlugins( SiteFacts $facts ): CheckResult {
		$inactive = array_filter( $facts->plugins, static fn( array $plugin ): bool => ! $plugin['active'] );

		return count( $inactive ) >= 5
			? new CheckResult(
				'inactive_plugins',
				'Nieaktywne wtyczki',
				CheckResult::STATUS_WARNING,
				sprintf( '%d nieaktywnych wtyczek pozostaje zainstalowanych.', count( $inactive ) ),
				'Nieaktywne wtyczki nadal mogą zawierać podatny kod dostępny z sieci. Usuń te, których nie planujesz używać.',
				array( 'inactive' => count( $inactive ), 'total' => count( $facts->plugins ) )
			)
			: new CheckResult(
				'inactive_plugins',
				'Nieaktywne wtyczki',
				CheckResult::STATUS_GOOD,
				sprintf( '%d nieaktywnych wtyczek.', count( $inactive ) )
			);
	}
}
