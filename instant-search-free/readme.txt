=== DoSieci Instant Search ===
Contributors: dosieci
Tags: woocommerce, search, live search, autocomplete, products
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Szybkie podpowiedzi wyszukiwania dla WooCommerce i WordPressa. Działa lokalnie — nie wymaga klucza API i nie wysyła zapytań na zewnątrz.

== Description ==

Podpowiedzi wyszukiwania pojawiają się pod standardowym polem wyszukiwania WordPressa podczas pisania.

Zapytanie jest wykonywane wyłącznie na bazie Twojej witryny. Nic nie jest wysyłane do żadnej usługi zewnętrznej, więc nie ma czego konfigurować ani na co się zgadzać.

= Wydajność =

Wyszukiwanie używa dopasowania od początku tytułu (indeksowanego), a nie kosztownego wzorca LIKE %fraza% na treści wpisu. To różnica między 10 ms a kilkoma sekundami przy katalogu rzędu 100 tys. produktów.

= Bezpieczeństwo =

Endpoint podpowiedzi zwraca wyłącznie treści opublikowane — szkice, prywatne i ukryte produkty nigdy się w nim nie pojawią.

== Installation ==

1. Wgraj wtyczkę i aktywuj ją.
2. Wejdź w Ustawienia → Instant Search i wybierz przeszukiwany typ treści.

== Changelog ==

= 1.0.0 =
* Pierwsze wydanie.
