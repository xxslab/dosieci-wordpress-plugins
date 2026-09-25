# DoSieci WordPress Plugins

Monorepo z wtyczkami WordPress/WooCommerce rozwijanymi przez DoSieci. Kod
pochodzi z prywatnego monorepo [Laravel License Plugin
Hub](https://github.com/xxslab/Laravel-License-Plugin-Hub-WP-AI-Operator)
(katalog `plugins/`); od importu (2026-09-24) to repozytorium jest dla wtyczek
źródłem prawdy — kopia w monorepo Huba jest już nieaktualna.

## Wtyczki

| Wtyczka | Katalog | Slug WordPress.org | Wersja | Status | Testy |
|---|---|---|---|---|---|
| DoSieci WP Doctor | `wp-doctor-free` | `dosieci-wp-doctor` | 1.0.0 | ✅ gotowa na WordPress.org | 18 ✅ |
| DoSieci Clean URLs | `clean-urls-free` | `dosieci-clean-urls` | 1.0.0 | ✅ gotowa na WordPress.org | 21 ✅ |
| DoSieci Instant Search | `instant-search-free` | `dosieci-instant-search` | 1.0.0 | ✅ gotowa na WordPress.org | 17 ✅ |
| DoSieci SEO Doctor | `seo-doctor-free` | `dosieci-seo-doctor` | 1.0.0 | ✅ gotowa na WordPress.org | 22 ✅ |
| DoSieci Translator | `translator-free` | `dosieci-translator` | 1.0.0 | ✅ gotowa na WordPress.org | 13 ✅ |
| DoSieci eBay Connector | `ebay-connector-free` | `dosieci-ebay-connector` | 1.0.0 | ✅ gotowa na WordPress.org | 14 ✅ |
| DoSieci AI Operator | `ai-operator` | — | 1.2.0-dev | ✅ EN+PL, 0 błędów Plugin Check — patrz [niżej](#dosieci-ai-operator-120-dev) | 336 ✅ |
| `*-pro` (6 wtyczek) | `*-pro` | — | 0.0.1-dev | 🚧 puste szkielety, bez logiki | — |

Wtyczki darmowe mają angielskie teksty źródłowe (wymóg WordPress.org) i
dołączone polskie tłumaczenie (`languages/*-pl_PL.po/.mo/.l10n.php`), które
WordPress ładuje automatycznie na witrynach po polsku.

## Budowanie paczek

```bash
bin/build.sh                    # wszystkie 6 darmowych wtyczek
bin/build.sh translator-free    # jedna
```

Wynik: `dist/<slug>/` i `dist/<slug>-<wersja>.zip` — gotowe do wgrania na
WordPress.org albo do instalacji ręcznej. Pliki deweloperskie (`tests/`,
`composer.json`, `phpunit.xml.dist`…) są pomijane według `.distignore`.
Testy jednostkowe: `cd <katalog> && composer install && vendor/bin/phpunit`.

## Weryfikacja przed wydaniem (2026-09-24)

Na lokalnej instalacji WordPress 7.1.2 (SQLite) + WooCommerce 11.1.2:

- **Plugin Check 2.1.0** (oficjalne narzędzie recenzentów WordPress.org), także
  z testami eksperymentalnymi: **0 błędów we wszystkich sześciu** (start: 44
  błędy i 22 ostrzeżenia). Zostało 6 ostrzeżeń, wszystkie świadome:
  - WP Doctor ×3 — „wp” w nazwie; kod Plugin Check sam opisuje to jako
    „dozwolone, ale z ostrzeżeniem”. Jeśli recenzent poprosi o zmianę, plan B
    to „DoSieci Site Doctor”.
  - Instant Search ×2 — skrypt i styl ładowane na każdej stronie; celowo, bo
    pole wyszukiwania jest zwykle w nagłówku każdej strony (razem ~6 KB).
  - SEO Doctor ×1 — bezpośrednie wywołanie OpenAI; wtyczka najpierw używa
    klienta AI WordPressa 7.0+ (Ustawienia → Łączniki), a własny klucz OpenAI
    to tylko zapas dla starszych WordPressów.
- **PHPCompatibility 8.1+**: jedyna uwaga to zapowiedź zmiany `trim()` w PHP
  8.6 (dodatkowo usunie znak form feed) — bez wpływu na te wtyczki.
- **Testy funkcjonalne w przeglądarce i HTTP**: wszystkie ekrany w EN i PL,
  frontend, deinstalacja (usuwa dane, poza celowo zachowaną mapą przekierowań
  Clean URLs), realne wywołania DeepL / OpenAI / eBay na fałszywych kluczach
  (ścieżki błędów), Instant Search w Twenty Twenty-Five i Storefront, pusty
  `debug.log` przy `WP_DEBUG`.

Najważniejsze błędy znalezione i naprawione przy tej okazji: Translator
kasował ukośniki wsteczne z treści i nie dawał się cofnąć na produktach (brak
rewizji); Clean URLs potrafił wyczyścić treść wpisu (KSES), gubił adresy
podstron i skanował wyłącznie wpisy; Instant Search ujawniał ukryte produkty i
wyświetlał ceny jako `&#122;&#322;`; eBay Connector zawsze szukał na ebay.com;
SEO Doctor wymuszał odpowiedzi po polsku. Szczegóły w historii commitów.

## Zgłoszenie na WordPress.org — lista kroków

1. Konto na wordpress.org: **`vvalik`** (wszystkie readme mają
   `Contributors: vvalik`). E-mail konta powinien być w domenie
   `@dosieci.pl` — WordPress.org potwierdza tak własność marki z nazwy
   wtyczki.
2. Zgłaszaj **po jednej** wtyczce (limit WordPress.org: jedna w kolejce
   naraz) przez https://wordpress.org/plugins/developers/add/ — wgrywasz ZIP
   z `dist/`. Slug powstaje z nazwy wtyczki i **po akceptacji nie da się go
   zmienić**; wszystkie sześć slugów z tabeli było wolnych 2026-09-24.
   Proponowana kolejność: Clean URLs, Translator, eBay Connector (czyste
   w Plugin Check) → Instant Search → SEO Doctor → WP Doctor.
3. Po akceptacji: wgraj kod do SVN (`trunk/` + `tags/1.0.0/`), a zrzuty
   ekranu z `wporg-assets/<slug>/` do katalogu `assets/` w SVN. Banera
   (772×250, 1544×500) i ikony (128×128, 256×256) jeszcze nie ma — do
   zaprojektowania.
4. Polskie tłumaczenie: zaimportuj `languages/<slug>-pl_PL.po` na
   translate.wordpress.org i poproś zespół Polyglots o rolę PTE dla swojej
   wtyczki, żeby paczki językowe szły z WordPress.org.

## DoSieci AI Operator 1.2.0-dev

Na `master` monorepo Huba ta wtyczka ma 1.1.0; tu jest wersja z niescalonego
brancha `claude/ai-operator-site-builder-1.2` (commit `f74efe9`), bo to ścisłe
rozszerzenie 1.1.0 (tryb planu zbiorczego z rollbackiem, kreator sklepu
WooCommerce). Nieukończone: import mediów, adapter Elementora, import demo
motywu.

Stan po przejściu 2026-09-25 (gałąź `ai-operator-release`, commity od
`6c9a662` do `ab4a4ea`, w tym 5 commitów `worktree-agent-afce099efccdf7dcf`
scalonych osobnym subagentem dla `includes/Domain/SiteBuilder/**` i
`includes/Adapter/WordPress/SiteBuilder/**`):

- **Trzy tryby dostawcy AI**, wybierane w Ustawieniach: **DoSieci Hub**
  (domyślny, kredyty z planu), **Łączniki AI WordPressa** (nowość — BYOK
  przez `wp_ai_client_prompt()` WordPressa 7.0+, klucz trzyma i zarządza nim
  sam WordPress w Ustawienia → Łączniki), i bezpośredni **BYOK**
  OpenAI/Anthropic z własnym kluczem w bazie wtyczki. Zweryfikowane
  end-to-end na testbedzie (parowanie z lokalnym Hubem, wywołanie narzędzia,
  zatwierdzenie, zapis do WordPressa) przed jednym z resetów środowiska;
  tryb Łączników zweryfikowany ponownie po scaleniu z lokalnym fałszywym
  OpenAI.
- **Angielskie teksty źródłowe w całej wtyczce** (wymóg WordPress.org) +
  dołączone tłumaczenie polskie (`languages/dosieci-ai-operator-pl_PL.po/
  .mo/.l10n.php`, 526 komunikatów), zweryfikowane na żywo przełączeniem
  testbedu na `pl_PL`.
- **Plugin Check 2.1.0**: **0 błędów** (start tej rundy: ~137 wpisów, z tego
  ~99 błędów). Zostało 11 ostrzeżeń, wszystkie świadome: bezpośrednie
  zapytania do własnych tabel wtyczki (log audytowy, plan Site Buildera,
  diagnostyka tylko do odczytu) i bezpośrednia integracja z Anthropic/OpenAI
  w trybach BYOK (zapasowa wobec Łączników WordPressa).
- Przy okazji znaleziony i naprawiony **prawdziwy błąd runtime**: literalny
  `\` przed `$` w dwóch formatach `sprintf()` (`WriteToolFactory`) powodował
  `Uncaught ValueError` przy każdej próbie instalacji nieistniejącego sluga
  wtyczki/motywu z WordPress.org — wychwycony dopiero przez generowanie
  `.pot`/`.po` i walidację placeholderów, nie przez istniejące testy.
- `uninstall.php` sprząta teraz też tabele i opcje Site Buildera (wcześniej
  czyścił tylko log audytowy i opcje z wersji 1.0).
- 336/336 testów PHPUnit, 8/8 testów Node (`assets/site-builder.js`).

Wymaga jeszcze przed trafieniem do klientów: testu end-to-end na prawdziwym
stagingu Huba (license-staging.dosieci.pl) z realnym parowaniem i realnym
kluczem Anthropic — poprzednia weryfikacja szła przez lokalny fałszywy Hub w
scratchpadzie, który padł ofiarą resetu środowiska w trakcie tej rundy;
decyzji, czy/kiedy zgłaszać na WordPress.org (płatna/BYOK wtyczka — inny
proces niż sześć darmowych powyżej) i podbicia wersji z `1.2.0-dev` na
`1.2.0` przy realnym wydaniu.

## Pochodzenie

| Co | Skąd |
|---|---|
| 12 wtyczek (poza `ai-operator`) | `xxslab/Laravel-License-Plugin-Hub-WP-AI-Operator`, `master`, `3b1e919` |
| `ai-operator` | ten sam repozytorium, branch `claude/ai-operator-site-builder-1.2`, `f74efe9` |

## Licencja

GPL-2.0-or-later — zgodnie z nagłówkiem każdej wtyczki. Pełny tekst w
[LICENSE](LICENSE).
