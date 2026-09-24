# DoSieci WordPress Plugins

Monorepo z wtyczkami WordPress/WooCommerce rozwijanymi przez DoSieci. Kod
źródłowy pochodzi z prywatnego monorepo [Laravel License Plugin
Hub](https://github.com/xxslab/Laravel-License-Plugin-Hub-WP-AI-Operator)
(katalog `plugins/`), gdzie żyje razem z Hubem licencyjnym (Laravel) i
pakietami współdzielonymi. To repozytorium zawiera wyłącznie same wtyczki,
gotowe do instalacji w `wp-content/plugins/<nazwa>` albo do spakowania do
ZIP-a.

## Wtyczki

| Wtyczka | Katalog | Wersja | Status | Testy jednostkowe |
|---|---|---|---|---|
| DoSieci AI Operator | `ai-operator` | **1.2.0-dev** | 🧪 rozwojowa, niescalona — patrz [niżej](#dosieci-ai-operator-120-dev) | 330/330 ✅ |
| DoSieci Clean URLs | `clean-urls-free` | 1.0.0 | ✅ produkcyjna | 14/14 ✅ |
| DoSieci Clean URLs Pro | `clean-urls-pro` | 0.0.1-dev | 🚧 szkielet Fazy 0, brak logiki domenowej | — (nic do testowania) |
| DoSieci eBay Connector | `ebay-connector-free` | 1.0.0 | ✅ produkcyjna | 12/12 ✅ |
| DoSieci eBay Connector Pro | `ebay-connector-pro` | 0.0.1-dev | 🚧 szkielet Fazy 0, brak logiki domenowej | — |
| DoSieci Instant Search | `instant-search-free` | 1.0.0 | ✅ produkcyjna | 10/10 ✅ |
| DoSieci Instant Search Pro | `instant-search-pro` | 0.0.1-dev | 🚧 szkielet Fazy 0, brak logiki domenowej | — |
| DoSieci SEO Doctor | `seo-doctor-free` | 1.0.0 | ✅ produkcyjna | 18/18 ✅ |
| DoSieci SEO Doctor Pro | `seo-doctor-pro` | 0.0.1-dev | 🚧 szkielet Fazy 0, brak logiki domenowej | — |
| DoSieci Translator for WooCommerce | `translator-free` | 1.0.0 | ✅ produkcyjna | 10/10 ✅ |
| DoSieci Translator Pro | `translator-pro` | 0.0.1-dev | 🚧 szkielet Fazy 0, brak logiki domenowej | — |
| DoSieci WP Doctor | `wp-doctor-free` | 1.0.0 | ✅ produkcyjna | 15/15 ✅ |
| DoSieci WP Doctor Pro | `wp-doctor-pro` | 0.0.1-dev | 🚧 szkielet Fazy 0, brak logiki domenowej | — |

**"Pro" = puste szkielety.** Wszystkie sześć wtyczek `*-pro` to celowo puste
szkielety Fazy 0 (55 linii: nagłówek, stałe, puste hooki aktywacji) —
mówi to wprost komentarz w każdym pliku głównym. Nie mają logiki domenowej,
nie da się ich dziś sprzedawać jako produkt. Zostawione w repo zgodnie z
zasadą projektu „nic nie usuwaj z powodu zmiany pozycjonowania" — do
dokończenia później.

## DoSieci AI Operator 1.2.0-dev

W monorepo źródłowym wtyczka `ai-operator` na branchu `master` (ten, na
który wskazywał link z zadania) stoi na **1.1.0**. Development poszedł
jednak dalej na osobnym, **niescalonym** branchu
`claude/ai-operator-site-builder-1.2` (ostatni commit 2026‑08‑17, 84 pliki,
+15675/-11 linii względem mastera — praktycznie czysty dodatek, nic z
1.1.0 nie ubyło). To właśnie ten branch trafił do tego repo jako aktualna
wersja `ai-operator`, bo jest wyraźnie bardziej rozwinięty i przechodzi
własne testy.

Co nowego wg `docs/architecture/AI_OPERATOR_SITE_BUILDER_1_2.md` z tamtego
brancha (uznany tam za jedyne źródło prawdy o stanie faktycznym, ponad
dokumentem roadmapy, który jest już nieaktualny w części o WooCommerce):

- **Gotowe i zweryfikowane na realnej instalacji WordPress** (wg tej
  dokumentacji, nie mojej weryfikacji — nie miałem tu środowiska WP):
  tryb planu zbiorczego (batch plan: model proponuje cały plan, człowiek
  zatwierdza go jako całość, wykonanie krok po kroku przez te same bramki
  co w 1.1.0), rollback odwracalnych kroków, generowanie treści świadome
  bloków Gutenberga, oraz **kreator sklepu WooCommerce** (opis w języku
  naturalnym → blueprint → plan → wykonanie z audytem i uzgadnianiem już
  istniejących zasobów).
- **Niezaimplementowane, celowo**: import/upload mediów, adapter
  Elementora, import treści demo motywu, kompozycja z gotowych wzorców
  bloków (template parts).

Co sam zweryfikowałem w tej sesji: `php -l` czyste na wszystkich plikach,
`composer install` + pełny `vendor/bin/phpunit` → **330/330 testów, 912
asercji, zielono**. Nie mam tu instalacji WordPress, więc claim o
weryfikacji "na realnej instalacji" pochodzi z dokumentacji brancha, nie
ode mnie — jeśli to ma iść na produkcję, warto powtórzyć chociaż smoke
test na stagingu przed udostępnieniem klientom.

Poprawiłem tylko `readme.txt` (Stable tag + wpis w Changelogu), który w
źródle wciąż mówił „1.1.0" mimo że plik główny wtyczki i tak deklaruje już
`1.2.0-dev` — reszta kodu jest nietknięta.

## Pochodzenie / traceability

| Co | Skąd |
|---|---|
| 12 wtyczek (wszystkie poza `ai-operator`) | `xxslab/Laravel-License-Plugin-Hub-WP-AI-Operator`, branch `master`, commit `3b1e919afdc63944cbf6eb6de0cfc0dd62ccd0a3` |
| `ai-operator` | ten sam repozytorium, branch `claude/ai-operator-site-builder-1.2` (niescalony do mastera), commit `f74efe96226b69bb702a5cf25236208a79dff13d` |

Sprawdzone przed importem: wszystkie pozostałe branche repozytorium
źródłowego (`claude/ai-operator-1.1-production-integration`,
`claude/elinker-entitlement-integration`, `release-a094`) są już w pełni
scalone do `master` — nie mają niczego nowszego. Jedyny inny rozjechany
branch, `claude/project-structure-analysis-x8xlk1`, to starsza i znacznie
mniejsza próba tego samego pomysłu (wspólny przodek z `site-builder-1.2`
w tagu `production-2026-08-10`) — pominięty jako nieaktualny.

Żadna z tych 13 wtyczek nie znalazła się jako wdrożona kopia na serwerze
produkcyjnym `srv.dosieci.pl` ani w innym katalogu lokalnym z nowszą
zawartością niż w monorepo źródłowym — sprawdzone przed importem.

## Weryfikacja wykonana przed importem

- `php -l` na każdym pliku `.php` wszystkich 13 wtyczek — czysto.
- `composer install` + `vendor/bin/phpunit` osobno dla każdej wtyczki,
  która ma testy (wszystkie `*-free` + `ai-operator`) — wszystkie zielone
  (patrz tabela wyżej). Wtyczki `*-pro` nie mają czego testować (puste
  szkielety).
- Skan pod kątem sekretów (klucze API, hasła, klucze prywatne) w całym
  `plugins/` źródłowym — jedyne trafienia to nazwy klas/opcji
  (`SecretScrubber`, `RequestSigner`, pola ustawień licencji/API) i
  oczywiście fałszywe wartości testowe (`sk-ant-SECRETSECRETSECRET-…`,
  `sk-proj-ABCDEFG…`) w testach jednostkowych. Nic realnego do
  publikacji.

Nie wykonano (bo środowisko na to nie pozwala): instalacji na żywym
WordPressie/WooCommerce, testu na WordPress.org Plugin Check, ręcznego
code review linia po linii pod kątem bezpieczeństwa poza automatycznym
skanem sekretów.

## Licencja

GPL-2.0-or-later — zgodnie z nagłówkiem każdej wtyczki. Pełny tekst w
[LICENSE](LICENSE).
