=== DoSieci AI Operator ===
Contributors: vvalik
Tags: ai, woocommerce, diagnostics, assistant, site health
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.2.0-dev
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Asystent AI w wp-adminie. Rozmawiasz, a operator diagnozuje i — po Twoim zatwierdzeniu — buduje Twoją witrynę WordPress i WooCommerce.

== Description ==

DoSieci AI Operator dodaje do wp-admina czat z asystentem, który potrafi samodzielnie sprawdzić stan Twojej witryny — wersję PHP, wtyczki, motyw, zadania cron, rozmiar autoloadowanych opcji, podsumowanie bazy danych, stan WooCommerce — a po włączeniu trybu zmian także ją zbudować: utworzyć strony i wpisy, zainstalować motyw lub wtyczkę z katalogu WordPress.org, złożyć menu, ustawić stronę główną i zmienić ustawienia witryny.

= Dwa tryby pracy =

**DoSieci Hub** (domyślnie) — rozmowa idzie podpisanym żądaniem (HMAC-SHA256) do DoSieci License Hub, który rozlicza zużycie z kredytów Twojego planu i dopiero po swojej stronie woła model. W bazie Twojego WordPressa nie ma wtedy żadnego klucza AI.

**Własny klucz API (BYOK)** — wtyczka łączy się bezpośrednio z OpenAI albo Anthropic Twoim własnym kluczem. Kredyty DoSieci nie są zużywane, a za zapytania płacisz bezpośrednio dostawcy. Klucz zapisywany jest w bazie tej witryny i wysyłany wyłącznie do wybranego dostawcy po HTTPS — nie trafia do DoSieci ani do logu audytowego.

= Bezpieczeństwo =

* Model **nigdy** nie dostaje możliwości wykonania dowolnego PHP, SQL-a ani polecenia powłoki — może wywołać wyłącznie nazwane, zadeklarowane narzędzie.
* Narzędzia zmieniające witrynę są **domyślnie wyłączone**. Dopóki ich nie włączysz, nie są w ogóle rejestrowane — model o nich nie wie.
* Po włączeniu **każda pojedyncza zmiana wymaga osobnego kliknięcia „Zatwierdź”** w czacie. Nie ma trybu „ufaj i rób”, a zbiorcza zgoda nie istnieje: jedno zatwierdzenie dotyczy jednego działania z konkretnymi argumentami, które widzisz przed kliknięciem.
* Każde narzędzie ma własne wymagane uprawnienie WordPressa, sprawdzane dla **zalogowanego człowieka**, który prowadzi rozmowę. AI nigdy nie ma większych uprawnień niż użytkownik przy klawiaturze.
* Motywy i wtyczki instalowane są **wyłącznie z oficjalnego katalogu WordPress.org po slugu**. Nie ma narzędzia „zainstaluj z adresu URL” — byłaby to furtka do uruchomienia dowolnego kodu.
* Ustawienia witryny można zmieniać tylko z **zamkniętej listy dozwolonych opcji**. Opcje umożliwiające przejęcie witryny (rejestracja użytkowników, rola domyślna, adres witryny, lista aktywnych wtyczek) nie są na niej obecne.
* Usuwanie treści przenosi ją **do kosza**, nigdy nie kasuje trwale.
* Argumenty wygenerowane przez model są walidowane względem schematu przed wykonaniem — traktujemy je jak każde inne niezaufane dane wejściowe.
* Każda próba wywołania narzędzia (również odrzucona) trafia do lokalnego logu audytowego. Sekrety nigdy nie są w nim zapisywane.
* Deaktywacja wtyczki niczego nie usuwa. Odinstalowanie usuwa dane wyłącznie wtedy, gdy wcześniej świadomie włączysz taką opcję w ustawieniach.

= Wymagania =

Aktywna subskrypcja DoSieci AI Operator i token parowania z panelu DoSieci — albo własny klucz API OpenAI lub Anthropic.

== Installation ==

1. Wgraj wtyczkę i aktywuj ją.
2. Przejdź do **DoSieci AI → Połączenie**.
3. Wklej jednorazowy token parowania z panelu DoSieci i zapisz.
4. Przejdź do **DoSieci AI → Czat**.

== Frequently Asked Questions ==

= Czy muszę podać swój klucz OpenAI/Anthropic? =

Nie. To jest różnica względem darmowych wtyczek DoSieci (Translator, SEO Doctor), które działają w modelu BYOK. AI Operator korzysta z hostowanego modelu po stronie DoSieci — Twoja witryna nigdy nie widzi klucza dostawcy.

= Czy operator może coś zepsuć na mojej witrynie? =

W wersji 1.0.0 wszystkie narzędzia są tylko do odczytu — nie ma narzędzia, które zapisuje, usuwa albo modyfikuje dane. Narzędzia zapisujące pojawią się dopiero z podglądem zmian i obowiązkowym potwierdzeniem per akcja.

= Co się stanie, gdy DoSieci będzie niedostępne? =

Czat pokaże komunikat o błędzie. Reszta wp-admina i WooCommerce działa normalnie — niedostępność AI nigdy nie blokuje witryny.

== Changelog ==

= 1.2.0-dev =
* Uwaga: wersja rozwojowa, wydana z niescalonej gałęzi roboczej (nie ma jeszcze oficjalnego wydania od zespołu DoSieci). Zweryfikowana testami jednostkowymi (330/330) i, według dokumentacji architektury, ręcznie na realnej instalacji WordPress dla części "core" i WooCommerce.
* Site Builder — tryb planu zbiorczego: model proponuje kompletny, uporządkowany plan wielu działań na raz, człowiek zatwierdza cały plan jednym kliknięciem (z możliwością wglądu w każdy krok), a wykonanie przechodzi przez te same bramki co pojedyncze zatwierdzenie w 1.1.0. Błąd kroku zatrzymuje resztę planu zamiast kontynuować po cichu.
* Cofanie (rollback) dla odwracalnych kroków już wykonanego planu.
* Generowanie treści świadome bloków Gutenberga (zamiast surowego HTML) dla części operacji.
* Kreator sklepu WooCommerce: opis sklepu w języku naturalnym → typowany blueprint (kategorie, produkty, ceny, strony sklepu) → plan → wykonanie z audytem i uzgadnianiem już istniejących zasobów, żeby powtórne uruchomienie nie tworzyło duplikatów.
* Braki znane i celowo nieukończone w tej wersji: import mediów (wyszukiwanie/upload/obraz wyróżniający), adapter Elementora, import treści demo motywu, kompozycja z gotowych wzorców bloków/template parts — patrz README repozytorium.

= 1.1.0 =
* Własny klucz API (BYOK): bezpośrednia obsługa OpenAI i Anthropic obok trybu DoSieci Hub.
* Narzędzia budujące witrynę: tworzenie i edycja stron/wpisów, instalacja i aktywacja motywów oraz wtyczek z WordPress.org, menu nawigacyjne, strona główna, kategorie i tagi, wybrane ustawienia witryny.
* Zatwierdzanie zmian: każde działanie zapisujące zatrzymuje rozmowę i czeka na osobne potwierdzenie człowieka, z podglądem dokładnych argumentów.
* Zamknięta lista dozwolonych ustawień witryny; opcje umożliwiające przejęcie witryny są niedostępne dla operatora.
* Usuwanie treści przenosi do kosza zamiast kasować trwale.


= 1.0.0 =
* Pierwsze wydanie: parowanie z DoSieci License Hub, czat w wp-adminie, 12 narzędzi diagnostycznych tylko do odczytu, lokalny log audytowy, historia rozmów per użytkownik.
