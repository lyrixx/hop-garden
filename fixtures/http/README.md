# Recorded HTTP responses

Served instead of the real APIs when `APP_DEMO=1` (see `src/DataSource/Demo/RecordedResponses.php`).
They follow the response format of each API, with sample values for 4 plants
(Hydrangea macrophylla, Hydrangea paniculata, a rose, lavender) and one cold snap.

- `trefle/aliases.json` maps a slugged query ("hortensia", "rose"…) to a file of `trefle/search/`;
  any other query finds nothing, and the agent falls back to its own knowledge. A French name such as "rosier" finds
  nothing either: the species search asks for its Latin name, answered in demo mode by `fixtures/llm/latin-names.json`
  (`App\DataSource\Demo\RecordedLatinNames`).
- `open-meteo/forecast-frost.json` is moved to start on the current day (of the app clock), at the position asked: the
  same cold snap in every garden.
- `open-meteo/geocoding/<city>.json` are the places of a few cities (Paris and its homonyms, Lyon, Lille, Nantes,
  Bordeaux, London, Paron, Auxerre) and `<postcode>.json` the ones of two postcodes of the Yonne (89100, 89000), whatever
  the language and the count asked; any other name finds none (`_empty.json`). So "89100 Paron" finds Paron: the
  postcode is searched alone, then the name among its places (`App\DataSource\OpenMeteo\GeocodingClient`). The garden
  form suggests these names when it finds nothing (`RecordedResponses::placeQueries()`). Paron, Auxerre and the
  postcodes are recorded as Open-Meteo answered in November 2026 (`language=en`, `count=5` for a name, `count=100` for a
  postcode); the older ones are trimmed to a few places, and a part of their postcodes. A new file is a new suggestion.
