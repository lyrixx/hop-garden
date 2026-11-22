# Recorded HTTP responses

Served instead of the real APIs when `APP_DEMO=1` (see `src/DataSource/Demo/RecordedResponses.php`).
They follow the response format of each API, with sample values for 4 plants
(Hydrangea macrophylla, Hydrangea paniculata, a rose, lavender) and one cold snap.

- `trefle/aliases.json` maps a slugged query ("hortensia", "rose"…) to a file of `trefle/search/`;
  any other query finds nothing, and the agent falls back to its own knowledge. A French name such as "rosier" finds
  nothing either: the species search asks for its Latin name, answered in demo mode by `fixtures/llm/latin-names.json`
  (`App\DataSource\Demo\RecordedLatinNames`).
- `open-meteo/forecast-frost.json` is moved to start on the current day (of the app clock).
