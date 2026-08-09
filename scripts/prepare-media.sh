#!/bin/sh
set -eu

project_dir=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
prototype_dir=${KUKA_PROTOTYPE_DIR:-/Users/yasir.arslan/Desktop/kukaisland}
source_dir="$prototype_dir/public/images/demo"
target_dir="$project_dir/seed-media"

prepare_story_media() {
	story_tmp=$(mktemp -d "${TMPDIR:-/tmp}/kuka-story-6b.XXXXXX")
	trap 'rm -rf "$story_tmp"' EXIT HUP INT TERM

	if ! command -v curl >/dev/null 2>&1 || ! command -v sips >/dev/null 2>&1; then
		echo "Hikâye medyası için curl ve sips gerekiyor." >&2
		exit 1
	fi

	# Pexels originals verified on 2026-08-09; attribution and page URLs live in
	# docs/GORSEL_KAYNAKLARI.md. Source files stay temporary, prepared crops local.
	set -- \
		"01|30923399|3264|1836|1958|2448" \
		"02|30049907|4032|2268|2419|3024" \
		"03|20536225|4000|2250|2400|3000" \
		"04|19457051|3024|1701|3024|3780" \
		"05|21554937|6000|3375|3200|4000" \
		"06|37049868|3168|1782|1958|2448"

	for spec do
		old_ifs=$IFS
		IFS='|'
		set -- $spec
		IFS=$old_ifs
		number=$1
		pexels_id=$2
		desktop_width=$3
		desktop_height=$4
		mobile_width=$5
		mobile_height=$6
		desktop_target="$target_dir/story-$number-desktop.jpg"
		mobile_target="$target_dir/story-$number-mobile.jpg"
		[ -f "$desktop_target" ] && [ -f "$mobile_target" ] && continue

		source_file="$story_tmp/story-$number-source.jpg"
		curl -L --fail --silent --show-error \
			"https://images.pexels.com/photos/$pexels_id/pexels-photo-$pexels_id.jpeg?cs=srgb&fm=jpg" \
			-o "$source_file"
		sips --cropToHeightWidth "$desktop_height" "$desktop_width" "$source_file" --out "$story_tmp/story-$number-desktop-crop.jpg" >/dev/null
		sips --resampleHeightWidth 1080 1920 "$story_tmp/story-$number-desktop-crop.jpg" --out "$desktop_target" >/dev/null
		sips --cropToHeightWidth "$mobile_height" "$mobile_width" "$source_file" --out "$story_tmp/story-$number-mobile-crop.jpg" >/dev/null
		sips --resampleHeightWidth 1500 1200 "$story_tmp/story-$number-mobile-crop.jpg" --out "$mobile_target" >/dev/null
	done

	trap - EXIT HUP INT TERM
	rm -rf "$story_tmp"
}

mkdir -p "$target_dir"
if [ ! -f "$target_dir/noir-asymmetric-top.jpg" ]; then
	if [ ! -d "$source_dir" ]; then
		echo "Demo medya kaynağı bulunamadı: $source_dir" >&2
		echo "KUKA_PROTOTYPE_DIR ile salt okunur prototip dizinini belirtin." >&2
		exit 1
	fi
	cp "$source_dir"/*.jpg "$target_dir"/
	echo "Pilot medya salt okunur prototip kaynağından yerel seed-media dizinine kopyalandı."
fi

prepare_story_media
echo "Faz 6B hikâye medyası: 6 masaüstü ve 6 mobil kadraj hazır."
