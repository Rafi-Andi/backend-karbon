#!/usr/bin/env bash
#
# Uji cepat penyedia AI validasi foto sampah (server-side), tanpa lewat aplikasi.
# Berguna saat menjawab: "VPS-nya diblokir Google atau tidak?" dan
# "key/model penyedia baru benar atau tidak?".
#
# Pemakaian (jalankan dari folder aplikasi, mis. /opt/karbonkita):
#   bash scripts/test-ai-provider.sh                  # pakai .env + gambar bawaan
#   bash scripts/test-ai-provider.sh foto-sampah.jpg  # uji dengan foto asli
#
# Nilai diambil dari environment dulu, lalu dari .env di folder kerja:
#   AI_DRIVER (gemini|openai), AI_BASE_URL, AI_MODEL, AI_API_KEY,
#   AI_ENDPOINT_PATH, AI_MIN_CONFIDENCE
# (nama GEMINI_* lama ikut dibaca bila AI_* kosong)
#
# Contoh hasil yang benar:
#   [HTTP 200] 0.31s  {"is_valid":true,"waste_category":"plastic","confidence":0.98,...}
# Arti status:
#   200 = penyedia menerima permintaan (foto dinilai). Lanjut cek nilai is_valid.
#   400 + "User location is not supported" = IP server diblokir Google.
#   401 = key salah. 404 = nama model salah. 429 = kuota habis (tunggu/naik tier).
set -uo pipefail

IMAGE_PATH="${1:-}"

env_value() {
    [ -f .env ] || return 0
    grep -E "^[[:space:]]*$1=" .env | tail -n 1 | cut -d= -f2- \
        | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//" \
        | tr -d '\r' | sed -e 's/[[:space:]]*$//'
}

val() { # val <primary> <fallback> — environment menang atas .env
    local primary="$1" fallback="${2:-}" value
    value="$(printenv "$primary" 2>/dev/null || true)"
    [ -n "$value" ] && { printf '%s' "$value"; return; }
    value="$(env_value "$primary")"
    [ -n "$value" ] && { printf '%s' "$value"; return; }
    value="$(printenv "$fallback" 2>/dev/null || true)"
    [ -n "$value" ] && { printf '%s' "$value"; return; }
    env_value "$fallback"
}

DRIVER="$(val AI_DRIVER GEMINI_DRIVER)"; DRIVER="${DRIVER:-gemini}"
KEY="$(val AI_API_KEY GEMINI_API_KEY)"
MODEL="$(val AI_MODEL GEMINI_MODEL)"
BASE_URL="$(val AI_BASE_URL GEMINI_BASE_URL)"
ENDPOINT_PATH="$(val AI_ENDPOINT_PATH GEMINI_ENDPOINT_PATH)"
MIN_CONFIDENCE="$(val AI_MIN_CONFIDENCE GEMINI_MIN_CONFIDENCE)"; MIN_CONFIDENCE="${MIN_CONFIDENCE:-85}"

case "$DRIVER" in
    openai|groq|openrouter|deepseek|openai-compatible|compatible)
        DRIVER="openai"
        MODEL="${MODEL:-qwen/qwen3.8-27b}"
        BASE_URL="${BASE_URL:-https://api.groq.com/openai/v1}"
        ENDPOINT_PATH="${ENDPOINT_PATH:-/chat/completions}"
        ;;
    *)
        DRIVER="gemini"
        MODEL="${MODEL:-gemini-3.5-flash-lite}"
        BASE_URL="${BASE_URL:-https://generativelanguage.googleapis.com}"
        ENDPOINT_PATH="${ENDPOINT_PATH:-/v1beta/models/{model}:generateContent}"
        ;;
esac

if [ -z "$KEY" ]; then
    echo "GAGAL: AI_API_KEY (atau GEMINI_API_KEY) kosong. Isi dulu di .env." >&2
    exit 2
fi

# MIME mengikuti gambar asli bila disertakan.
MIME="image/png"
if [ -n "$IMAGE_PATH" ]; then
    [ -f "$IMAGE_PATH" ] || { echo "GAGAL: berkas '$IMAGE_PATH' tidak ada." >&2; exit 2; }
    case "${IMAGE_PATH##*.}" in
        jpg|JPG|jpeg|JPEG) MIME="image/jpeg" ;;
        png|PNG) MIME="image/png" ;;
        webp|WEBP) MIME="image/webp" ;;
    esac
    B64="$(base64 < "$IMAGE_PATH" | tr -d '\n')"
    SUMBER="$IMAGE_PATH ($(wc -c < "$IMAGE_PATH") byte, $MIME)"
else
    # PNG 64x64 sekali pakai — uji koneksi/key/model (Groq butuh minimal 32 px).
    B64="iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAIAAAAlC+aJAAAAaklEQVR42u3aMQ0AIRREwW/uTFAj4mQgAIEggB4LFBzkkkmegWm22mij/7oAADgMeFL+NAAAgIMr9Ja6pWszCgAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAADgdgkAAACw3ATzXy9bVik16wAAAABJRU5ErkJggg=="
    SUMBER="gambar bawaan 64x64 (uji koneksi saja, bukan foto sampah asli)"
fi

URL="${BASE_URL%/}${ENDPOINT_PATH//\{model\}/$MODEL}"

PROMPT="You are a waste-sorting validator for KarbonKita. Analyze the attached photo and "
PROMPT+="respond with ONLY a JSON object, no markdown: is_valid (boolean), confidence "
PROMPT+="(number 0-100), waste_category (plastic|paper|organic|electronic|residue|mixed|"
PROMPT+="unknown), reason (1-2 sentences in Bahasa Indonesia)."

TMP_BODY="$(mktemp)"
TMP_RESP="$(mktemp)"
trap 'rm -f "$TMP_BODY" "$TMP_RESP"' EXIT

if [ "$DRIVER" = "openai" ]; then
    cat > "$TMP_BODY" <<JSON
{"model":"$MODEL","messages":[{"role":"user","content":[{"type":"text","text":"$PROMPT"},{"type":"image_url","image_url":{"url":"data:$MIME;base64,$B64"}}]}],"response_format":{"type":"json_object"},"temperature":0,"max_completion_tokens":800}
JSON
    HTTP_STATUS="$(curl -sS -o "$TMP_RESP" -w '%{http_code} %{time_total}' \
        -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' \
        -X POST "$URL" --data-binary "@$TMP_BODY" || true)"
else
    cat > "$TMP_BODY" <<JSON
{"contents":[{"parts":[{"text":"$PROMPT"},{"inline_data":{"mime_type":"$MIME","data":"$B64"}}]}],"generationConfig":{"responseMimeType":"application/json","temperature":0.1}}
JSON
    HTTP_STATUS="$(curl -sS -o "$TMP_RESP" -w '%{http_code} %{time_total}' \
        -H 'Content-Type: application/json' -X POST "$URL?key=$KEY" \
        --data-binary "@$TMP_BODY" || true)"
fi

BODY="$(cat "$TMP_RESP")"
STATUS_CODE="${HTTP_STATUS%% *}"; STATUS_CODE="${STATUS_CODE:-000}"
ELAPSED="${HTTP_STATUS##* }"

echo "driver=$DRIVER model=$MODEL base=$BASE_URL endpoint=$ENDPOINT_PATH"
echo "gambar=$SUMBER ambang_min=$MIN_CONFIDENCE"
echo "--- respons penyedia ---"
printf '%s\n' "$BODY" | head -c 1500
echo
echo "--- ringkasan ---"
echo "[HTTP $STATUS_CODE] ${ELAPSED}s  $URL"

case "$STATUS_CODE" in
    200)
        echo "OK: penyedia menjawab. Cek is_valid/confidence pada JSON di atas."
        echo "Catatan: confidence 0-1 (mis. 0.98) otomatis dinormalkan backend ke 0-100."
        ;;
    400)
        if printf '%s' "$BODY" | grep -qi 'user location is not supported'; then
            echo "GAGAL: IP server ini DIBLOKIR Google (bukan salah key/model)."
            echo "Solusi: AI_DRIVER=openai, AI_BASE_URL=https://api.groq.com/openai/v1,"
            echo "        AI_MODEL=qwen/qwen3.8-27b, AI_API_KEY=gsk_..."
        else
            echo "GAGAL: permintaan ditolak (400). Cek gambar atau isi field pada JSON di atas."
        fi
        ;;
    401|403) echo "GAGAL: key ditolak. Periksa AI_API_KEY." ;;
    404)
        if printf '%s' "$BODY" | grep -qi 'unknown request url\|not found'; then
            echo "GAGAL: URL endpoint salah. Periksa AI_BASE_URL / AI_ENDPOINT_PATH."
        else
            echo "GAGAL: nama model tidak dikenal penyedia. Periksa AI_MODEL."
        fi
        ;;
    413) echo "GAGAL: gambar terlalu besar. Perkecil foto atau turunkan WASTE_MAX_IMAGE_KB." ;;
    429) echo "KUOTA HABIS (429): tunggu, atau naikkan tier penyedia." ;;
    000) echo "GAGAL: tidak bisa menghubungi $URL (jaringan/DNS/firewall VPS)." ;;
    *) echo "GAGAL: status tidak terduga ($STATUS_CODE)." ;;
esac

[ "$STATUS_CODE" = "200" ] || exit 1
