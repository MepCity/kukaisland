#!/bin/sh
set -eu

project_dir=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
prototype_dir=${KUKA_PROTOTYPE_DIR:-/Users/yasir.arslan/Desktop/kukaisland}
source_dir="$prototype_dir/public/images/demo"
target_dir="$project_dir/seed-media"

if [ -f "$target_dir/noir-asymmetric-top.jpg" ]; then
  exit 0
fi
if [ ! -d "$source_dir" ]; then
  echo "Demo medya kaynağı bulunamadı: $source_dir" >&2
  echo "KUKA_PROTOTYPE_DIR ile salt okunur prototip dizinini belirtin." >&2
  exit 1
fi

mkdir -p "$target_dir"
cp "$source_dir"/*.jpg "$target_dir"/
echo "Pilot medya salt okunur prototip kaynağından yerel seed-media dizinine kopyalandı."

