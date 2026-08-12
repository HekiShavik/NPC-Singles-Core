# NP Singles Core 0.1.0

Første migrationstrin for NP Singles-platformen.

Core ejer nu:
- fælles game registry
- provider-kontrakten
- neutral metadata-kontrakt + legacy fallback
- hooket `nps_core_ready`

Pokémon og Lorcana beholder i denne version deres eksisterende UI/services. De registrerer sig i Core, så næste migration kan flytte fælles services uden at ændre den synlige funktionalitet i samme trin.

## Mål for næste migration
1. fælles HTTP/debug/cache primitives
2. fælles inventory history repository/service
3. fælles product index
4. fælles admin shell/assets
5. ProductBuilder opdeles i fælles Woo builder + game-specifik mapper

Magic: The Gathering skal bygges direkte mod Core-kontrakten og må ikke kopiere PSW/LSW-strukturen som et tredje selvstændigt plugin.
