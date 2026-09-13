# NP Singles Core

Shared engine for NP Singles WooCommerce integrations.

## 0.4.0

Core now owns the shared AdminController and ProductImportService workflows in addition to the existing cache, HTTP, product index, inventory history and admin shell.

Game integrations remain responsible for their own API/data source, card normalization, finish rules, ProductBuilder, SKU conventions and game-specific UI details. Thin compatibility adapters keep the existing PSW/LSW class names stable while delegating to Core.

## 0.6.0
- Shared LocalCardIndex table for game-neutral local printing search.
- Generic LocalCardSearchController for game plugins.

## 0.7.0
- Offentligt game-agnostisk `CardCatalog` over den delte `LocalCardIndex`.
- `nps_core_get_card()` og `nps_core_search_cards()` til add-ons som Pricing.
- Struktureret opslag på stabile game/card-ID'er, navn, sæt og collector number.

## 0.7.1
- Singles-hubben husker senest valgte game_id pr. WordPress-bruger via user meta og bruger det som standard ved næste besøg.

## 0.8.2
- Tilføjer et batch-baseret efterbehandlingsværktøj under hvert spils Singles-indstillinger.
- Værktøjet anvender spillets aktuelle standardvægt på eksisterende Singles-produkter uden at ændre lager, pris eller status.
- Jobbet kan genoptages efter en netværksfejl ved at trykke på knappen igen, så længe standardvægten ikke er ændret.

## 0.8.13
- Fælles registry for eksterne datakilder på tværs af Singles-spil og pricing.
- Global `Datakilder`-fane i Singles med aktive providers, credentials og lokale daglige/månedlige requestbudgetter.
- Månedlige budgetter kan have egen nulstillingsdag; forbrug beregnes fra requesthistorik og kræver derfor ingen cron til nulstilling.
- Immutable raw provider-responses gemmes med checksum, tidspunkt, request og metadata, så normaliserede data senere kan spores tilbage til kilden.
- Fælles persistens til resumable syncjobs med cursor/checkpoint og statuser som fx `paused_quota`.
- Provider resources kan markeres `missing`, `incomplete` eller `complete`; komplette resources får ingen automatisk recheck-dato.
- Offentlige Core-funktioner til provider-registrering, credentials, quota-check, requestlog, raw storage, syncjobs og resource-state.
