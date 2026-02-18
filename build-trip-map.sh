#!/bin/bash
# Build trip_line_map.json from GTFS Regional Static data
# Requires: GTFS_STATIC_KEY environment variable
# Output: trip_line_map.json (trip_id → "line|destination")

set -e

KEY="${GTFS_STATIC_KEY:?Set GTFS_STATIC_KEY}"
WORKDIR=$(mktemp -d)
echo "Downloading GTFS Static for SL..."
curl -s --compressed -o "$WORKDIR/gtfs.zip" "https://opendata.samtrafiken.se/gtfs/sl/sl.zip?key=$KEY"
cd "$WORKDIR"
unzip -o gtfs.zip trips.txt stop_times.txt stops.txt routes.txt

echo "Building trip_line_map.json..."
node - << 'JS'
const fs = require("fs"), readline = require("readline");
async function csv(f) {
  const r = readline.createInterface({input:fs.createReadStream(f),crlfDelay:Infinity});
  let h=null, rows=[];
  for await (const l of r) { if(!h){h=l.split(",");continue;} const v=l.split(","),o={}; h.forEach((k,i)=>o[k]=v[i]||""); rows.push(o); }
  return rows;
}
(async()=>{
  const routes=await csv("routes.txt"), r2l={}; routes.forEach(r=>r2l[r.route_id]=r.route_short_name||"");
  const trips=await csv("trips.txt"), t2r={}; trips.forEach(t=>t2r[t.trip_id]=t.route_id);
  const stops=await csv("stops.txt"), sn={}; stops.forEach(s=>sn[s.stop_id]=s.stop_name);
  const rl=readline.createInterface({input:fs.createReadStream("stop_times.txt"),crlfDelay:Infinity});
  let h=null, last={};
  for await (const l of rl) { if(!h){h=l.split(",");continue;} const v=l.split(","),tid=v[0],seq=parseInt(v[4])||0; if(!last[tid]||seq>last[tid].s) last[tid]={id:v[3],s:seq}; }
  const map={};
  for (const tid in t2r) { const line=r2l[t2r[tid]]; if(!line) continue; const d=last[tid]?sn[last[tid].id]||"":""; map[tid]=d?line+"|"+d:line; }
  fs.writeFileSync("trip_line_map.json",JSON.stringify(map));
  console.log("Entries:",Object.keys(map).length,"Size:",(JSON.stringify(map).length/1024/1024).toFixed(1),"MB");
})();
JS

mv trip_line_map.json "$OLDPWD/"
cd "$OLDPWD"
rm -rf "$WORKDIR"
echo "Done! trip_line_map.json updated."
