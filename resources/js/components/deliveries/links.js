/** Open an address in the phone's maps app (Google Maps search). */
export function mapsUrl(address) {
    return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(address)}`;
}

/** tel: link with only the digits and a leading +. */
export function telUrl(phone) {
    return `tel:${String(phone).replace(/[^\d+]/g, '')}`;
}
