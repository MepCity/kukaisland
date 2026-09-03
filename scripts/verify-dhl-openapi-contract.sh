#!/bin/sh
# The carrier contract, measured against the vendor's own OpenAPI documents.
#
# Two questions, and neither can be answered by reading the plugin alone:
#
#   1. Are the specification files the ones that were reviewed? Answered by
#      SHA-256, against the SHA256SUMS file that ships beside them.
#   2. Does the client actually call the operations those files declare?
#      Answered by extracting every method+path pair from the documents and
#      requiring each one this integration uses to be present -- and requiring
#      the status dictionary, the base paths and the host to match too.
#
# Host-side, because the documents live outside the work tree on purpose:
# ~/.config/kuka-island/dhl-openapi/. They are vendor material and the
# repository is not where they belong.
#
# A machine without the documents reports SKIPPED rather than failing, so a
# clean clone or a CI runner is not blocked by material it was never given.
set -eu

project_dir=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
spec_dir="${KUKA_DHL_OPENAPI_DIR:-${XDG_CONFIG_HOME:-$HOME/.config}/kuka-island/dhl-openapi}"

if [ ! -d "$spec_dir" ] || [ ! -f "$spec_dir/SHA256SUMS" ]; then
  echo "DHL_OPENAPI_CONTRACT=SKIPPED|reason:spec_directory_absent|path_outside_repo:yes"
  exit 0
fi

case "$spec_dir" in
  "$project_dir"/*)
    echo "DHL_OPENAPI_CONTRACT=FAIL|reason:spec_path_inside_repository" >&2
    exit 1
    ;;
esac

if command -v shasum >/dev/null 2>&1; then
  checksum_cmd="shasum -a 256"
elif command -v sha256sum >/dev/null 2>&1; then
  checksum_cmd="sha256sum"
else
  echo "DHL_OPENAPI_CONTRACT=FAIL|reason:no_sha256_tool" >&2
  exit 1
fi

# Checksums are verified from inside the spec directory so SHA256SUMS' bare
# file names resolve, and the result is reduced to a count rather than echoed:
# the file list is vendor material, the verdict is what this suite reports.
checked=0
mismatched=0
while IFS= read -r line; do
  [ -z "$line" ] && continue
  expected=$(printf '%s\n' "$line" | awk '{print $1}')
  name=$(printf '%s\n' "$line" | sed 's/^[0-9a-f]*  *//')
  checked=$((checked + 1))
  if [ ! -f "$spec_dir/$name" ]; then
    mismatched=$((mismatched + 1))
    continue
  fi
  actual=$(cd "$spec_dir" && $checksum_cmd "$name" | awk '{print $1}')
  [ "$actual" = "$expected" ] || mismatched=$((mismatched + 1))
done < "$spec_dir/SHA256SUMS"

if [ "$checked" -eq 0 ] || [ "$mismatched" -ne 0 ]; then
  printf 'DHL_OPENAPI_CONTRACT=FAIL|reason:checksum_mismatch|checked:%s|mismatched:%s\n' "$checked" "$mismatched" >&2
  exit 1
fi

if ! command -v python3 >/dev/null 2>&1; then
  printf 'DHL_OPENAPI_CONTRACT=SKIPPED|reason:python3_absent|checksums:%s/%s\n' "$checked" "$checked"
  exit 0
fi

KUKA_SPEC_DIR="$spec_dir" KUKA_PROJECT_DIR="$project_dir" KUKA_CHECKED="$checked" python3 - <<'PY'
import json
import os
import re
import sys

spec_dir = os.environ['KUKA_SPEC_DIR']
project_dir = os.environ['KUKA_PROJECT_DIR']
checked = os.environ['KUKA_CHECKED']

plugin = os.path.join(project_dir, 'wp-content', 'plugins', 'kuka-island-shipping-automation')
client_src = open(os.path.join(plugin, 'includes', 'shipping', 'dhl', 'class-dhl-client.php'), encoding='utf-8').read()
config_src = open(os.path.join(plugin, 'includes', 'shipping', 'dhl', 'class-dhl-config.php'), encoding='utf-8').read()
status_src = open(os.path.join(plugin, 'includes', 'shipping', 'class-shipment-status.php'), encoding='utf-8').read()
token_src = open(os.path.join(plugin, 'includes', 'shipping', 'dhl', 'class-dhl-token-store.php'), encoding='utf-8').read()
mapper_src = open(os.path.join(plugin, 'includes', 'shipping', 'dhl', 'class-dhl-order-mapper.php'), encoding='utf-8').read()

docs = {
    'identity': 'Identity_API-1.0.json',
    'standard_cmd': 'Standard_Command_API-1.0.json',
    'barcode_cmd': 'Barcode_Command_API-1.0.json',
    'standard_query': 'Standard_Query_API-1.0.json',
    'cbs_info': 'CBS_Info_API-1.0.json',
}

specs = {}
for key, name in docs.items():
    with open(os.path.join(spec_dir, name), encoding='utf-8') as handle:
        specs[key] = json.load(handle)

failures = []

# --- host and base paths -------------------------------------------------
hosts = {spec.get('host') for spec in specs.values()}
if hosts != {'testapi.mngkargo.com.tr'}:
    failures.append('host_drift')

if "'testapi.mngkargo.com.tr'" not in config_src:
    failures.append('config_host_missing')

base_paths = {key: spec.get('basePath') for key, spec in specs.items()}
expected_urls = {
    'identity': 'https://testapi.mngkargo.com.tr/mngapi/api/token',
    'standard_cmd': 'https://testapi.mngkargo.com.tr' + (base_paths['standard_cmd'] or ''),
    'barcode_cmd': 'https://testapi.mngkargo.com.tr' + (base_paths['barcode_cmd'] or ''),
    'standard_query': 'https://testapi.mngkargo.com.tr' + (base_paths['standard_query'] or ''),
    'cbs_info': 'https://testapi.mngkargo.com.tr' + (base_paths['cbs_info'] or ''),
}
for key, url in expected_urls.items():
    if url not in config_src:
        failures.append('base_url_missing:' + key)

# --- operations the client must reach ------------------------------------
declared = set()
for key, spec in specs.items():
    for path, methods in spec.get('paths', {}).items():
        for method in methods:
            declared.add((key, method.upper(), path))

used = [
    ('identity', 'POST', '/token'),
    ('standard_cmd', 'POST', '/createOrder'),
    ('standard_cmd', 'PUT', '/updateorder'),
    ('standard_cmd', 'PUT', '/cancelorder/{refrenceId}'),
    ('barcode_cmd', 'POST', '/createbarcode'),
    ('barcode_cmd', 'PUT', '/updateshipment'),
    ('barcode_cmd', 'PUT', '/cancelshipment'),
    ('standard_query', 'GET', '/getorder/{referenceId}'),
    ('standard_query', 'GET', '/getshipment/{referenceId}'),
    ('standard_query', 'GET', '/getshipmentstatus/{referenceId}'),
    ('standard_query', 'GET', '/trackshipment/{referenceId}'),
    ('cbs_info', 'GET', '/getcities'),
    ('cbs_info', 'GET', '/getdistricts/{cityCode}'),
]

for entry in used:
    if entry not in declared:
        failures.append('operation_not_declared:%s%s' % (entry[1], entry[2]))

# The literal path segment the client appends must appear in its source, so a
# typo in the PHP is caught rather than only a typo in this list.
adapter_src = client_src + config_src + token_src
for _service, _method, path in used:
    literal = path.split('{')[0].rstrip('/')
    if literal and literal not in adapter_src:
        failures.append('client_missing_path:' + literal)

# --- Identity request contract -------------------------------------------
identity_required = set(specs['identity']['definitions']['GenerateTokenRequest'].get('required', []))
if identity_required != {'customerNumber', 'password', 'identityType'}:
    failures.append('identity_required_drift')

# --- Order required fields ----------------------------------------------
order_required = set(specs['standard_cmd']['definitions']['Order'].get('required', []))
expected_order_required = {
    'referenceId', 'shipmentServiceType', 'packagingType', 'content',
    'smsPreference1', 'smsPreference2', 'smsPreference3', 'paymentType',
    'deliveryType', 'description',
}
if order_required != expected_order_required:
    failures.append('order_required_drift')

for field in sorted(expected_order_required):
    if "'%s'" % field not in mapper_src:
        failures.append('mapper_missing_field:' + field)

piece_required = set(specs['standard_cmd']['definitions']['OrderPieceList'].get('required', []))
if piece_required != {'barcode', 'desi', 'kg'}:
    failures.append('piece_required_drift')

# --- status dictionary ---------------------------------------------------
dictionary = specs['standard_query']['definitions']['ShipmentOUT']['properties']['shipmentStatusCode']['description']
pairs = dict(re.findall(r'(\d+)\s*:\s*([A-Za-zÇĞİÖŞÜçğıöşü_]+)', dictionary))
if sorted(pairs.keys(), key=int) != [str(n) for n in range(1, 9)]:
    failures.append('status_dictionary_drift')

# Every documented code must be a constant in the dictionary class, and the
# class must not invent a ninth.
for code in range(1, 9):
    if ('=> self::LIFECYCLE' not in status_src) or ('CODE_' not in status_src):
        failures.append('status_class_shape')
        break

declared_codes = re.findall(r'public const CODE_[A-Z_]+\s*=\s*(\d+);', status_src)
if sorted(int(c) for c in declared_codes) != list(range(1, 9)):
    failures.append('status_codes_drift')

# --- enumerations the mapper translates ----------------------------------
enum_checks = [
    ('shipmentServiceType', specs['standard_cmd']['definitions']['Order']['properties']['shipmentServiceType']['description'], {1, 7, 8}),
    ('packagingType', specs['standard_cmd']['definitions']['Order']['properties']['packagingType']['description'], {1, 2, 3, 4}),
    ('deliveryType', specs['standard_cmd']['definitions']['Order']['properties']['deliveryType']['description'], {1, 2}),
]
for name, description, expected in enum_checks:
    found = {int(n) for n in re.findall(r'(\d+)\s*:\s*[A-ZÇĞİÖŞÜ]', description)}
    if found != expected:
        failures.append('enum_drift:' + name)

if failures:
    print('DHL_OPENAPI_CONTRACT=FAIL|checksums:%s|reasons:%s' % (checked, ','.join(sorted(set(failures)))), file=sys.stderr)
    sys.exit(1)

print(
    'DHL_OPENAPI_CONTRACT=PASS|checksums:%s/%s|documents:5|operations_declared:%d|operations_used:%d|status_codes:8|host:pinned|base_paths:matched'
    % (checked, checked, len(declared), len(used))
)
PY
