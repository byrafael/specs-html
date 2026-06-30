spec() {
  curl -L --post301 -X POST --data-binary @"$1" \
    -H "Authorization: Bearer <TOKEN>" \
    https://cdn.rsrdev.com/specs/
}