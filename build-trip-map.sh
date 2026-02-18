#!/bin/bash
# Build trip_line_map.json from GTFS static data
# Requires: GTFS_STATIC_KEY (Trafiklab GTFS Sverige 2 API key)
#
# Usage: GTFS_STATIC_KEY=your_key ./build-trip-map.sh

set -e

if [ -z "$GTFS_STATIC_KEY" ]; then
    echo "Error: Set GTFS_STATIC_KEY environment variable"
    echo "Get a free key at https://www.trafiklab.se/api/gtfs-datasets/gtfs-regional/"
    exit 1
fi

echo "Downloading GTFS static data..."
curl -s --compressed -o /tmp/sl_gtfs.zip \
    "https://opendata.samtrafiken.se/gtfs/sl/sl.zip?key=${GTFS_STATIC_KEY}"

echo "Extracting trips.txt and routes.txt..."
unzip -o /tmp/sl_gtfs.zip trips.txt routes.txt -d /tmp/sl_gtfs/

echo "Building trip→line mapping..."
python3 -c "
import csv, json

routes = {}
with open('/tmp/sl_gtfs/routes.txt') as f:
    for row in csv.DictReader(f):
        routes[row['route_id']] = row['route_short_name']

trip_map = {}
with open('/tmp/sl_gtfs/trips.txt') as f:
    for row in csv.DictReader(f):
        line = routes.get(row['route_id'], '')
        if line:
            trip_map[row['trip_id']] = line

with open('trip_line_map.json', 'w') as f:
    json.dump(trip_map, f)

print(f'Done! {len(trip_map)} trip→line mappings saved to trip_line_map.json')
"

rm -rf /tmp/sl_gtfs /tmp/sl_gtfs.zip
echo "Cleanup done."
