#!/bin/sh
set -eu

project_dir=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
prototype_dir=${KUKA_PROTOTYPE_DIR:-/Users/yasir.arslan/Desktop/kukaisland}
source_dir="$prototype_dir/public/images/demo"
target_dir="$project_dir/seed-media"

prepare_story_media() {
	story_media_missing=0
	for number in 01 02 03 04 05 06; do
		if [ ! -f "$target_dir/story-$number-desktop.jpg" ] || [ ! -f "$target_dir/story-$number-mobile.jpg" ]; then
			story_media_missing=1
			break
		fi
	done
	[ "$story_media_missing" -eq 0 ] && return 0

	story_tmp=$(mktemp -d "${TMPDIR:-/tmp}/kuka-story-6b.XXXXXX")
	trap 'rm -rf "$story_tmp"' EXIT HUP INT TERM

	if ! command -v curl >/dev/null 2>&1 || ! command -v sips >/dev/null 2>&1; then
		echo "Hikâye medyası için curl ve sips gerekiyor." >&2
		exit 1
	fi
	if command -v shasum >/dev/null 2>&1; then
		checksum() { shasum -a 256 "$1" | awk '{print $1}'; }
	elif command -v sha256sum >/dev/null 2>&1; then
		checksum() { sha256sum "$1" | awk '{print $1}'; }
	else
		echo "Hikâye medyası için SHA-256 aracı gerekiyor." >&2
		exit 1
	fi

	# Pexels originals verified on 2026-08-09; attribution and page URLs live in
	# docs/GORSEL_KAYNAKLARI.md. Source files stay temporary, prepared crops local.
	set -- \
		"01|30923399|3264|1836|1958|2448|7c7f6682c68d1696c82bbb88b7d2f624300410f40bb3289b09c13bd93f21b6e6" \
		"02|30049907|4032|2268|2419|3024|24fe09f78e3926541f0798e47c9fd446a011a8c6907d5fc5dce1d7595d5e1a08" \
		"03|20536225|4000|2250|2400|3000|7ec122b1f701767e28bf3924bb98ecb5a341620285fc5aff0b393156c60dd846" \
		"04|19457051|3024|1701|3024|3780|a15f1fe92d56e7f1833800e515ce7320426b1cc0dadd501e49a3afad3435d7f0" \
		"05|21554937|6000|3375|3200|4000|652e524285f8b9a49519b18888df0ad6d740d25eb08ad8658df8ece15146d181" \
		"06|37049868|3168|1782|1958|2448|f4a0b9d74db09661f8a38c2e025064a2ed36ffdc2bf677c55df1211c51108f86"

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
		expected_checksum=$7
		desktop_target="$target_dir/story-$number-desktop.jpg"
		mobile_target="$target_dir/story-$number-mobile.jpg"
		[ -f "$desktop_target" ] && [ -f "$mobile_target" ] && continue

		source_file="$story_tmp/story-$number-source.jpg"
		curl -L --fail --silent --show-error \
			"https://images.pexels.com/photos/$pexels_id/pexels-photo-$pexels_id.jpeg?cs=srgb&fm=jpg" \
			-o "$source_file"
		actual_checksum=$(checksum "$source_file")
		if [ "$actual_checksum" != "$expected_checksum" ]; then
			echo "Hikâye medyası checksum doğrulaması başarısız: $number" >&2
			exit 1
		fi
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
