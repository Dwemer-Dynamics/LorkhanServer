DROP TABLE IF EXISTS request_mac_nonces;
ALTER TABLE pairing_tokens DROP CONSTRAINT IF EXISTS pairing_tokens_mac_key_length;
ALTER TABLE pairing_tokens DROP COLUMN IF EXISTS mac_key;
