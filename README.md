# 🚌 Bussigt!

Real-time bus tracker for Stockholm's public transit. Pick your stop, see your buses live on the map — with ETA right in your pocket.

**Live:** [lookma.se/bussigt](https://lookma.se/bussigt/)

![Bussigt Screenshot](https://lookma.se/bussigt/apple-touch-icon.png)

## Features

- 🔍 **Search any stop** in Stockholm (6,500+ stops via SL open API)
- 🚍 **Auto-discover lines** serving your stop from real-time departures
- 📍 **Live bus positions** on a dark-mode Leaflet map
- ⏱️ **ETA to your home stop** shown on each bus marker
- 🚏 **Home stop marker** with correct coordinates
- ⚙️ **Persistent settings** — stop, lines, zoom level saved in localStorage
- 📱 **Mobile-first** — PWA-ready, safe area support for notch phones
- ⚡ **Server-side caching** — 5s TTL, all users share the same API calls

## Architecture

```
┌──────────┐     ┌──────────┐     ┌──────────────────┐
│  Browser  │────▶│  api.php │────▶│  Trafiklab GTFS-RT│
│ (Leaflet) │◀────│  (cache) │◀────│  VehiclePositions │
│           │     │          │     │  TripUpdates      │
└──────────┘     └──────────┘     └──────────────────┘
     │                │
     │                ├──▶ SL transport.integration.sl.se (stop search & lines)
     │                └──▶ trip_line_map.json (trip→line mapping from GTFS static)
     │
     └──▶ localStorage (settings, map view)
```

- **Frontend:** Single-file `index.html` with Leaflet.js, dark CARTO tiles
- **Backend:** `api.php` — protobuf parser, server-side cache, API stats
- **Data:** Trafiklab GTFS-RT for vehicle positions + SL open API for stop search

## Setup

### 1. Get API keys (free)

- **GTFS Regional Realtime** — [trafiklab.se](https://www.trafiklab.se/api/gtfs-datasets/gtfs-regional/) (Bronze: 30k calls/month)
- **GTFS Sverige 2 Static** — [trafiklab.se](https://www.trafiklab.se/api/gtfs-datasets/gtfs-sverige-2/) (for building trip→line map)

### 2. Configure

Edit `api.php` and replace `YOUR_TRAFIKLAB_GTFS_RT_KEY` with your realtime key.

### 3. Build trip→line mapping

```bash
GTFS_STATIC_KEY=your_static_key ./build-trip-map.sh
```

This creates `trip_line_map.json` (~2.5MB, ~96k mappings). Rebuild weekly/monthly as schedules change.

### 4. Deploy

Upload to any PHP-capable web host:
- `index.html`
- `api.php`
- `trip_line_map.json`
- `apple-touch-icon.png`

## API Endpoints

| Endpoint | Description |
|----------|-------------|
| `api.php?lines=409,449&stop=ID` | Vehicle positions + ETA |
| `api.php?action=search&q=odenplan` | Search stops |
| `api.php?action=lines&siteId=1079` | Lines at a stop |
| `api.php?action=stats` | API usage statistics |

## API Budget

With 5s server-side caching, all concurrent users share the same Trafiklab calls:

| Scenario | Calls/month | Level needed |
|----------|------------|--------------|
| Light (2h active/day) | ~43k | Silver |
| Medium (8h/day) | ~173k | Silver |
| Heavy (18h/day) | ~778k | Silver |

Bronze (30k/month) works for personal use. Silver (2M/month) handles real traffic. Both are free to request.

## Tech Stack

- **Frontend:** Vanilla JS, Leaflet.js, CARTO dark tiles
- **Backend:** PHP 8.x, raw protobuf parsing (no dependencies)
- **Data:** Trafiklab GTFS-RT, SL Open API (transport.integration.sl.se)
- **Storage:** localStorage, server-side file cache

## License

MIT

## Credits

Built by [Lookma](https://lookma.se) with help from Emma 🤖

Data: [Trafiklab](https://www.trafiklab.se) GTFS Regional · [SL](https://sl.se) Open API
