ALTER TABLE pairing_tokens ADD COLUMN IF NOT EXISTS mac_key bytea;
-- Legacy bootstrap hashes cannot recover their original key; the configured bootstrap path seeds
-- its 32-byte key explicitly. Existing rows retain a deterministic migration key until rotation.
UPDATE pairing_tokens SET mac_key = decode(token_hash, 'hex') WHERE mac_key IS NULL;
ALTER TABLE pairing_tokens ALTER COLUMN mac_key SET NOT NULL;
ALTER TABLE pairing_tokens ADD CONSTRAINT pairing_tokens_mac_key_length CHECK (octet_length(mac_key) = 32);

CREATE TABLE request_mac_nonces (
    pairing_token_id uuid NOT NULL REFERENCES pairing_tokens(pairing_token_id) ON DELETE CASCADE,
    nonce char(32) NOT NULL CHECK (nonce ~ '^[0-9a-f]{32}$'),
    request_timestamp timestamptz NOT NULL,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (pairing_token_id, nonce)
);
CREATE INDEX request_mac_nonces_created ON request_mac_nonces(created_at);
